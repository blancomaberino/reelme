# Reelmap — continuation prompt

Paste the block below into a **fresh session** to continue the build. Everything the
next session needs is durable: memory (`reelmap-progress.md`), `tasks/tasks.json`, the
specs (`00`–`07`), and git history.

- **App code:** `~/Sites/reelmap` (`apps/api` Laravel; run gates via `docker exec api-laravel.test-1 …`)
- **Plan / specs / tasks:** `~/Sites/plans/reelmap`
- **Demo:** `http://localhost:8080/demo.html` (needs the queue worker running + env: a pulled
  `OLLAMA_TEXT_MODEL`, `GOOGLE_PLACES_API_KEY`, `AI_MIN_PUBLISH_CONFIDENCE=0.5` — see memory)

---

## Prompt

```
Continue building Reelmap. App code: ~/Sites/reelmap (apps/api Laravel, run gates via
`docker exec api-laravel.test-1 …`). Plan/specs/tasks: ~/Sites/plans/reelmap. Read your
memory (reelmap-progress.md) and tasks/tasks.json first to see what's done.

GOAL (definition of done): the M2 discovery experience works end-to-end as a web demo —
place detail with reviews, tags + search, feed, and public profiles/follows — with the
demo at http://localhost:8080/demo.html exercising each. Work the pending M2 tasks
(T-030→T-037, T-059) in dependency order.

HARD RULES:
- One task = one branch = one PR. For each: implement → Pint + PHPStan + full Pest green
  → adversarial security/correctness review (subagent) with findings fixed → CodeRabbit
  gate (approve.sh) → open PR → merge when API CI passes → mark the task done in tasks.json
  and update memory.
- VERIFY BEFORE CLAIMING: prove each user-facing feature end-to-end through the REAL HTTP
  API and the browser (Claude-in-Chrome) — not tinker or test-fakes — before saying it works.
  A past mistake was claiming victory off back-door tests; don't repeat it.
- Keep the pipeline demo working: the queue worker must run; env needs a pulled OLLAMA
  model + GOOGLE_PLACES_API_KEY + AI_MIN_PUBLISH_CONFIDENCE=0.5 (see memory).
- When a piece genuinely can't be done headless (Stripe, Apple/EAS, real IG tokens), stop
  and tell me exactly what to provide — don't fake it or silently skip.

Stop and report when the GOAL above is met and verified, or when you're blocked on
something only I can provide.
```

### Optional — auto-continue with a real exit (so it can't loop forever)

```
/ralph-loop --completion-promise 'M2_DISCOVERY_DONE' --max-iterations 40 <paste the prompt above>
```

The assistant only emits `<promise>M2_DISCOVERY_DONE</promise>` when the goal is genuinely
met and browser-verified.

---

## Scope options (pick one when you start)

- **Recommended:** finish the **M2 discovery experience** (above).
- **Smaller:** just place-detail + reviews + tags/search (T-030, T-031, T-059).
- **Bigger / needs unblocking:** push into **M3 mobile** (requires iOS simulator + EAS), or
  **M4 monetization** (requires Stripe). Tell the assistant when those are unblocked.

## Known env gotchas (from this session)
- Two repos: `~/Sites/plans/reelmap` (plan) vs `~/Sites/reelmap` (app + `.env`).
- Ollama: config default model may not be pulled — use a pulled one (`gemma4:latest`) or
  `ollama pull qwen2.5:14b` for better extraction.
- Small local models need Ollama **structured output** (already wired) to hit the schema.
- `.env` demo overrides can leak into tests — test-critical AI knobs are pinned in `phpunit.xml`.
- CI's `Install ffmpeg` step can be slow; the API job is the gate (CodeRabbit isn't required).
