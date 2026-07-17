<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Support\TagTranslations;
use Illuminate\Console\Command;

/**
 * Backfill `tags.name_i18n` from the seed dictionary (ADR-084 #2) for tags
 * created before localized names existed. Only fills the `es` key when it is
 * missing, so it never clobbers an AI/human translation (#4). Idempotent.
 */
class BackfillTagTranslations extends Command
{
    protected $signature = 'reelmap:tags:backfill-i18n';

    protected $description = 'Seed tags.name_i18n[es] from the translation dictionary (idempotent)';

    public function handle(): int
    {
        $count = 0;

        Tag::query()->chunkById(500, function ($tags) use (&$count): void {
            foreach ($tags as $tag) {
                $es = TagTranslations::es($tag->name);
                if ($es === null || ($tag->name_i18n['es'] ?? null) !== null) {
                    continue; // no translation, or already set — don't overwrite
                }
                $tag->name_i18n = [...($tag->name_i18n ?? []), 'es' => $es];
                $tag->saveQuietly(); // no search re-sync needed (name/slug unchanged)
                $count++;
            }
        });

        $this->components->info("Backfilled Spanish names for {$count} tags.");

        return self::SUCCESS;
    }
}
