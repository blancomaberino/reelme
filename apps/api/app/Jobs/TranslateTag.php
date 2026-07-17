<?php

namespace App\Jobs;

use App\Enums\TagKind;
use App\Models\Tag;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fill a tag's missing `name_i18n` labels with a cheap LLM translation (ADR-084
 * #4) — the long tail the seed dictionary doesn't cover. Best-effort: any
 * failure just leaves the English `name` as the fallback, so it never blocks
 * publishing and never retries into the ground. Dishes are never translated
 * (verbatim menu text); the dispatcher already excludes them.
 */
class TranslateTag implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 45;

    /** BCP-47 → English language name for the prompt. */
    private const LANGUAGE = ['es' => 'Spanish'];

    /** @param  list<string>  $locales */
    public function __construct(public readonly int $tagId, public readonly array $locales) {}

    public function handle(AnalysisEngine $engine): void
    {
        $tag = Tag::query()->find($this->tagId);
        if ($tag === null || $tag->kind === TagKind::Dish) {
            return;
        }

        $i18n = $tag->name_i18n ?? [];
        $added = false;
        foreach ($this->locales as $locale) {
            if (! isset(self::LANGUAGE[$locale]) || isset($i18n[$locale])) {
                continue; // unsupported, or already translated (dictionary / prior run)
            }
            $translation = $this->translate($engine, $tag->name, $locale);
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

    /** One translation, or null on any failure / an implausible (sentence-like) reply. */
    private function translate(AnalysisEngine $engine, string $name, string $locale): ?string
    {
        $language = self::LANGUAGE[$locale];
        $request = new GenerationRequest(
            systemPrompt: "You translate short restaurant/food discovery tags from English into {$language}. "
                .'Reply with ONLY the translation as a short noun phrase — no quotes, no punctuation, no explanation.',
            userParts: [GenerationPart::text($name)],
            temperature: 0.0,
        );

        try {
            $raw = $engine->generate($request, config('ai.openrouter.default_model'))->rawText;
        } catch (Throwable $e) {
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

        return mb_substr($line, 0, 80);
    }
}
