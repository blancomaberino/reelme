<?php

namespace App\Console\Commands;

use App\Models\Influencer;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Console\Command;

/**
 * Rebuild every search index from Postgres (T-031). Settings are pushed FIRST
 * so `_geo`/filterables exist before documents rely on them, then each index
 * is flushed and re-imported. Idempotent; run after settings changes or bulk
 * seeding (the seeders insert via DB::table and never sync to Scout).
 */
class ReindexSearch extends Command
{
    protected $signature = 'reelmap:search:reindex';

    protected $description = 'Push Meilisearch index settings, then flush + reimport places, tags, influencers';

    public function handle(): int
    {
        if (config('scout.driver') === 'meilisearch') {
            $this->call('scout:sync-index-settings');
        } else {
            $this->components->warn('scout.driver is not meilisearch — skipping index-settings sync.');
        }

        foreach ([Place::class, Tag::class, Influencer::class] as $model) {
            $this->call('scout:flush', ['model' => $model]);
            $this->call('scout:import', ['model' => $model]);
        }

        $this->components->info('Search reindex complete.');

        return self::SUCCESS;
    }
}
