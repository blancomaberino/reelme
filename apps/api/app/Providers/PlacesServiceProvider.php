<?php

namespace App\Providers;

use App\Services\Places\Enrichment\BusinessEnricher;
use App\Services\Places\Enrichment\BusinessEnrichmentSource;
use App\Services\Places\Enrichment\Sources\GoogleBusinessSource;
use App\Services\Places\Enrichment\Sources\ReviewsBusinessSource;
use App\Services\Places\Enrichment\Sources\WebsiteBusinessSource;
use App\Services\Places\PlaceEditor;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the "enrich as business" pipeline (T-084): the ordered
 * {@see BusinessEnrichmentSource} drivers behind
 * a {@see BusinessEnricher}. Order is merge priority — Google (authoritative
 * contact/hours) first, then the business website (fills gaps: image, cuisine,
 * address), then the review refresh (no field patch). Tests can rebind with a
 * fixed source set.
 */
class PlacesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BusinessEnricher::class, function (): BusinessEnricher {
            return new BusinessEnricher(
                sources: [
                    $this->app->make(GoogleBusinessSource::class),
                    $this->app->make(WebsiteBusinessSource::class),
                    $this->app->make(ReviewsBusinessSource::class),
                ],
                editor: $this->app->make(PlaceEditor::class),
            );
        });
    }
}
