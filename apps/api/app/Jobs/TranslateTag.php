<?php

namespace App\Jobs;

use App\Enums\TagKind;
use App\Models\Tag;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\LocalEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fill a tag's missing `name_i18n` labels with a cheap LLM translation (ADR-084
 * #4) — the long tail the seed dictionary doesn't cover. Local-first (ADR-005):
 * uses the free local Ollama engine when it's up, and only falls back to the
 * hosted engine when one is configured — so no remote API key is required to
 * run this. Best-effort: an unreachable engine or an implausible reply just
 * leaves the English `name` as the fallback. Dishes are never translated
 * (verbatim menu text); the dispatcher already excludes them.
 */
class TranslateTag implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * Keep this > ai.ollama.health_cache_seconds (30s) so a retry after a
     * transient local blip lands once the cached-healthy probe has expired.
     *
     * @var list<int>
     */
    public array $backoff = [60];

    public int $timeout = 45;

    /** BCP-47 → English language name for the prompt. */
    private const LANGUAGE = ['es' => 'Spanish'];

    /** @param  list<string>  $locales */
    public function __construct(public readonly int $tagId, public readonly array $locales) {}

    public function handle(): void
    {
        $tag = Tag::query()->find($this->tagId);
        if ($tag === null || $tag->kind === TagKind::Dish) {
            return;
        }

        [$engine, $model] = $this->pickEngine();
        if ($engine === null) {
            return; // no engine reachable/configured — keep the English fallback
        }

        $i18n = $tag->name_i18n ?? [];
        $added = false;
        foreach ($this->locales as $locale) {
            if (! isset(self::LANGUAGE[$locale]) || isset($i18n[$locale])) {
                continue; // unsupported, or already translated (dictionary / prior run)
            }
            $translation = $this->translate($engine, $model, $tag->name, $locale);
            if ($translation !== null) {
                $i18n[$locale] = $translation;
                $added = true;
            }
        }

        if ($added) {
            $tag->name_i18n = $i18n;
            $tag->save(); // re-syncs the search index with the new localized name
        }
    }

    /**
     * Local-first engine selection (ADR-005): the free Ollama engine when it's
     * healthy (null model → it picks its own text model), else the hosted engine
     * when configured, else nothing.
     *
     * @return array{0: AnalysisEngine|null, 1: string|null}
     */
    private function pickEngine(): array
    {
        $local = app(LocalEngine::class);
        if ($local->isHealthy()) {
            return [$local, null];
        }
        if (filled(config('ai.openrouter.api_key'))) {
            return [app(AnalysisEngine::class), config('ai.openrouter.default_model')];
        }

        return [null, null];
    }

    /** One translation, or null on any failure / an implausible (sentence-like) reply. */
    private function translate(AnalysisEngine $engine, ?string $model, string $name, string $locale): ?string
    {
        $language = self::LANGUAGE[$locale];
        $request = new GenerationRequest(
            systemPrompt: "You translate short restaurant/food discovery tags from English into {$language}. "
                .'Reply with ONLY the translation as a short noun phrase — no quotes, no punctuation, no explanation.',
            userParts: [GenerationPart::text($name)],
            temperature: 0.0,
        );

        try {
            $raw = $engine->generate($request, $model)->rawText;
        } catch (EngineUnavailable $e) {
            // Transport failure — let the job retry (tries/backoff) rather than
            // permanently leaving the tag English on a transient blip.
            throw $e;
        } catch (Throwable $e) {
            // Reached but failed (bad output, cost cap, …) — a retry won't help;
            // keep the English fallback.
            Log::warning('TranslateTag: engine call failed', ['tag_id' => $this->tagId, 'locale' => $locale, 'error' => $e->getMessage()]);

            return null;
        }

        return self::sanitize($raw);
    }

    /** Trim a model reply to a bare label; reject empties and sentence-like output. */
    private static function sanitize(string $raw): ?string
    {
        $line = trim((string) preg_split('/\r?\n/', trim($raw))[0]);
        $line = trim($line, " \t\"'«»“”.");
        // A real tag label is short; anything long is a refusal/explanation → drop it.
        if ($line === '' || mb_strlen($line) > 60) {
            return null;
        }

        return $line;
    }
}
