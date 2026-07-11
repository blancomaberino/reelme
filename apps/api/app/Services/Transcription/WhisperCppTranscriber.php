<?php

namespace App\Services\Transcription;

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use Illuminate\Support\Facades\Process;

/**
 * Local whisper.cpp driver (default). Runs the CLI, which writes `{wav}.json`
 * beside the input; we parse that file (not stdout — flags/format drift across
 * versions). Fails soft: a missing model or binary makes isAvailable() false,
 * which is exactly the hosted-fallback trigger.
 */
class WhisperCppTranscriber implements Transcriber
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        return $this->available ??= (is_file($this->modelPath()) && $this->binaryExists($this->bin()));
    }

    public function transcribe(string $wavPath): TranscriptionResult
    {
        $jsonPath = $wavPath.'.json';

        try {
            $result = Process::timeout((int) config('transcription.whisper_cpp.timeout', 900))->run([
                $this->bin(),
                '-m', $this->modelPath(),
                '-f', $wavPath,
                '--output-json',
                '-of', $wavPath, // → {wavPath}.json
                '--language', 'auto',
            ]);

            if (! $result->successful()) {
                throw new TranscriptionFailed('whisper.cpp failed: '.$result->errorOutput());
            }

            $raw = @file_get_contents($jsonPath);
            if ($raw === false) {
                throw new TranscriptionFailed('whisper.cpp produced no JSON output.');
            }

            /** @var array<string, mixed> $json */
            $json = json_decode($raw, true) ?: [];

            return $this->parse($json);
        } finally {
            // Reap the sidecar (it holds user speech) on every path, incl. errors.
            @unlink($jsonPath);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json): TranscriptionResult
    {
        $language = $json['result']['language'] ?? null;
        $segments = [];
        $texts = [];

        foreach ($json['transcription'] ?? [] as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start_ms' => (int) ($seg['offsets']['from'] ?? 0),
                'end_ms' => (int) ($seg['offsets']['to'] ?? 0),
                'text' => $text,
            ];
            $texts[] = $text;
        }

        $full = trim(implode(' ', $texts));

        // Music/silence hallucination: whisper loops one short phrase. Store empty.
        if ($full === '' || $this->looksLikeHallucination($segments)) {
            return TranscriptionResult::empty('whisper_cpp');
        }

        return new TranscriptionResult(
            language: is_string($language) ? $language : null,
            text: $full,
            segments: $segments,
            driver: 'whisper_cpp',
        );
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     */
    private function looksLikeHallucination(array $segments): bool
    {
        // ≥4 segments that are all the same short phrase → almost certainly a loop.
        if (count($segments) < 4) {
            return false;
        }

        $unique = array_unique(array_map(static fn (array $s): string => strtolower($s['text']), $segments));

        return count($unique) === 1;
    }

    private function bin(): string
    {
        return (string) config('transcription.whisper_cpp.bin', 'whisper-cli');
    }

    private function modelPath(): string
    {
        $dir = rtrim((string) config('transcription.whisper_cpp.models_dir'), '/');
        $model = (string) config('transcription.whisper_cpp.model', 'ggml-base');

        return "{$dir}/{$model}.bin";
    }

    private function binaryExists(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin);
        }

        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable(rtrim($dir, '/').'/'.$bin)) {
                return true;
            }
        }

        return false;
    }
}
