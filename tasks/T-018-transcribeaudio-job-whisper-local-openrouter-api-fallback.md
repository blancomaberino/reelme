# T-018 — TranscribeAudio job (Whisper local, OpenRouter/API fallback)

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-017
- **Target paths:** `apps/api/app/Jobs/TranscribeAudio.php`, `apps/api/app/Services/Transcription/`
- **Spec refs:** [04-analysis-pipeline.md#model-orchestration](../04-analysis-pipeline.md) (§1 stage table + TranscribeAudio notes, §3)

## Context

PrepareMedia (T-017) now emits a 16 kHz mono WAV `media_assets` row (or none, for silent videos). This task turns speech into the transcript that feeds the extraction prompt (T-021): local-first via whisper.cpp (cheap, private — mirroring the ADR-005 local-first posture), with a hosted fallback when the local binary/host is unavailable. The transcript persists on the source_post so all shares of the same post reuse it. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

## Implementation steps

1. **Migration** `add_transcript_json_to_source_posts` — `transcript_json jsonb` nullable on `source_posts` (04 §1 stores transcript there; 02's table spec predates it — this is the sanctioned addition). Shape: `{"language": "en", "text": "...", "segments": [{"start_ms": 0, "end_ms": 4200, "text": "..."}], "driver": "whisper_cpp", "empty": false}`. Add `'transcript_json' => 'array'` cast on `SourcePost`.
2. **`Transcriber` contract** (`apps/api/app/Services/Transcription/Transcriber.php`):
   ```php
   interface Transcriber
   {
       public function isAvailable(): bool;
       /** @throws TranscriptionFailed */
       public function transcribe(string $wavPath): TranscriptionResult; // language, text, segments[]
   }
   ```
   `TranscriptionResult` DTO with `language` (BCP-47), full `text`, `segments` (start/end ms + text), `driver`.
3. **Drivers**, selected by `TRANSCRIBER_DRIVER` (config `transcription.driver`, default `whisper_cpp`) via a manager/factory:
   - `WhisperCppTranscriber`: runs the whisper.cpp CLI via `Process` — `whisper-cli -m {models_dir}/{model}.bin -f audio.wav --output-json --language auto` (binary + model path from `config/transcription.php`: `WHISPER_BIN`, `WHISPER_MODEL` default `ggml-base`); parse the JSON output (per-segment `offsets` + detected `result.language`). `isAvailable()`: binary exists + model file exists (cached check). 900 s process ceiling per the stage timeout.
   - `OllamaWhisperTranscriber` (optional second local driver per 04 §1: "whisper.cpp binary (default) or Ollama-hosted Whisper"): POST WAV to a configured Ollama-adjacent runner URL; same result shape. `isAvailable()`: health endpoint reachable (2 s timeout).
   - `HostedTranscriber` (the fallback): OpenAI-compatible `POST /v1/audio/transcriptions` (multipart, `model: whisper-1`-class, `response_format: verbose_json` for segments) against `config('transcription.hosted.base_url')` + API key — this covers OpenAI or any compatible endpoint; keep provider generic since OpenRouter's audio support varies. Cost note recorded in result (`driver: hosted`).
4. **Fallback policy** in a `TranscriptionManager`: primary driver `isAvailable()` false **or** `transcribe()` throws → try hosted (if `transcription.hosted.enabled`); hosted also fails/disabled → job failure with taxonomy code `transcribe_error`. Log which driver won (structured log with `share_id`).
5. **`TranscribeAudio` job** (`apps/api/app/Jobs/TranscribeAudio.php`; queue **media** — spec's `transcribe` queue maps onto the `media` Horizon queue; tries 3, backoff `[60, 300, 900]`, timeout 900):
   - Status guard (`fetching`/`analyzing` window per chain position), idempotency: `source_posts.transcript_json` already set → return early (shared across shares of the same post).
   - **No audio asset** (silent video, `no_audio` from T-017): persist `{"empty": true, "text": "", "segments": [], "language": null}` and return success — silence is not failure (04 §1: "Empty/music-only audio yields empty transcript, not failure").
   - Otherwise: download WAV from the `media` disk to temp, run `TranscriptionManager`, persist `transcript_json`, clean temp in `finally`. Horizon tags `share:{id}`, `stage:transcribe`.
   - `failed()` hook: share → `failed`, `failure_reason: transcribe_error`.
6. **Tests** (`tests/Feature/Jobs/TranscribeAudioTest.php`), no live network/binaries:
   - `FakeTranscriber` bound in the container for pipeline tests.
   - `WhisperCppTranscriber` unit test with `Process::fake` returning a captured whisper.cpp JSON fixture (`tests/Fixtures/transcription/whisper_output.json`) → segments + language parsed correctly.
   - Manager fallback: primary `isAvailable() === false` → hosted driver called (`Http::fake` on the transcriptions endpoint with a `verbose_json` fixture); both unavailable → `TranscriptionFailed` → share failed with `transcribe_error`.
   - Silent video path: no audio asset → `transcript_json.empty === true`, share unaffected, chain continues.
   - Idempotency: pre-set `transcript_json` → transcriber never invoked (`Process::assertNothingRan()`).
   - Optional `->group('whisper')` real-binary test, skipped when `WHISPER_BIN` absent (not required in CI).

## Acceptance criteria

- [ ] whisper.cpp (or Ollama-hosted Whisper) transcription works behind a single `Transcriber` contract, driver chosen by `TRANSCRIBER_DRIVER`, with automatic language detection persisted.
- [ ] Hosted transcription fallback triggers when the local driver is unavailable or errors; both-unavailable maps to `transcribe_error` on `shares.failure_reason`.
- [ ] Transcript (language + full text + per-segment start/end timestamps) persisted on `source_posts.transcript_json`; segments feed the T-021 prompt's `TRANSCRIPT:` block.
- [ ] Silent/no-audio videos yield an empty transcript marker and the chain proceeds — never a failure.
- [ ] Job is idempotent (skips when transcript exists — including for a second share of the same source_post), status-guarded, on the `media` queue with Horizon tags.
- [ ] All CI tests run without whisper binary, Ollama, or network (Process/Http fakes + fixtures).
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan test --filter=TranscribeAudio
php artisan test --filter=Transcription
# local smoke with whisper.cpp installed and a prepared share:
php artisan tinker --execute="
  \$sp = \App\Models\SourcePost::whereNotNull('transcript_json')->first();
  echo \$sp->transcript_json['language'], ' / ', count(\$sp->transcript_json['segments']), ' segments', PHP_EOL;
  echo mb_substr(\$sp->transcript_json['text'], 0, 120);
"
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

Expected: suite green; tinker shows a detected language, segment count, and transcript text after a real run.

## Gotchas

- **whisper.cpp CLI flags/output moved across versions** (`main` → `whisper-cli`; `-oj`/`--output-json` writes `{input}.json` next to the file rather than stdout). Pin the invocation against the installed version at implementation time and parse the JSON *file*, not stdout. Keep binary+model paths in config, never hardcoded.
- Model files are hundreds of MB — never commit them; `isAvailable()` must fail soft when the model is missing (that's exactly the hosted-fallback trigger).
- Transcript lives on **source_posts**, not shares: two users sharing the same reel must not transcribe twice — idempotency key is the source_post. Guard the write with `whereNull('transcript_json')`-style update to survive concurrent shares.
- Whisper hallucinates on music-only audio (loops like "thanks for watching"): if mean segment no-speech probability is high or text is a single repeated n-gram, prefer storing empty. Cheap heuristic is fine; don't over-engineer.
- BCP-47 vs Whisper's ISO-639-1 codes: store what Whisper gives (`en`, `pt`); T-021's `post.language` from the LLM is stored separately — mismatch is fine per 04 §5.
- 900 s timeout: `$timeout` on the job AND `Process::timeout()` must both be set; Horizon's `timeout` must be < the queue's `retry_after` or you'll get duplicate runs (another reason idempotency matters).
- The hosted fallback sends user audio to a third party — gate behind `transcription.hosted.enabled` config (default on for prod, off in tests) and document in `.env.example`.
