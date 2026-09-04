<?php

namespace App\Models\Concerns;

use App\Models\Dish;
use App\Models\PlaceSource;
use App\Services\Places\DishMaterializer;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keeps a source's {@see Dish} rows in step with its extraction snapshot
 * (T-157), on the model itself — the same shape as {@see DerivesNameColumns},
 * which maintains `normalized_name`/`slug` on every save for the same reason.
 *
 * THIS is the rule's home, deliberately. A dish row is derived state, and the
 * state it derives from is one column on one table. Putting the rewrite at the
 * publish seam would have covered the path being written the day the feature
 * landed and missed the three others that already write that column
 * ({@see App\Services\Places\PlaceResolver::attach()},
 * {@see App\Services\Places\ResolvePendingPlace}, and PublishShare's
 * corrected-snapshot overwrite) — the failure that CLAUDE.md's "a new rule needs
 * every writer" describes, which passes its own test and is invisible to a
 * diff-scoped review.
 *
 * The one thing that DOES get past this hook is a query-builder write
 * (`DB::table('place_sources')->update([...])`), which fires no model events.
 * There is none today, and `DishMaterializerTest` fails if one appears.
 */
trait MaterializesDishes
{
    public static function bootMaterializesDishes(): void
    {
        static::saved(function (PlaceSource $source): void {
            // Only when the dish evidence actually moved: a source is saved on
            // publish (`published_at`) and on demotion (`is_primary`) too, and
            // rewriting the rows there would churn ids for no reason.
            if ($source->wasRecentlyCreated || $source->wasChanged('extraction_snapshot_json')) {
                app(DishMaterializer::class)->materialize($source);
            }
        });
    }

    /**
     * The dishes this source claims, in snapshot order.
     *
     * Eager-load it (`->with('dishes')`) anywhere the aggregation or a resource
     * reads dishes — `Model::preventLazyLoading()` is on in the test suite, so a
     * missed load fails loudly rather than becoming an N+1 in production.
     *
     * @return HasMany<Dish, $this>
     */
    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class)->orderBy('id');
    }
}
