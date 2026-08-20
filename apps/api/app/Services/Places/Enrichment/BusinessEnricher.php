<?php

namespace App\Services\Places\Enrichment;

use App\Enums\ContactFieldSource;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Services\Places\Enrichment\Sources\GoogleBusinessSource;
use App\Services\Places\PlaceEditor;
use Throwable;

/**
 * Orchestrates the "enrich as business" action (T-084): runs each configured
 * {@see BusinessEnrichmentSource} in order, failure-isolated, merges their
 * proposed patches (the first source to supply a field wins — authoritative
 * sources are ordered first), and applies the merged patch through the single
 * {@see PlaceEditor} write path. That editor drops any human-locked field and
 * writes the audit row, so a manual override always survives an enrichment.
 *
 * It NEVER throws: a source blowing up is reported and degrades to the others,
 * mirroring the review aggregator's registry.
 */
class BusinessEnricher
{
    /**
     * @param  list<BusinessEnrichmentSource>  $sources  in merge-priority order
     */
    public function __construct(
        private readonly array $sources,
        private readonly PlaceEditor $editor,
        private readonly GalleryBuilder $gallery,
    ) {}

    public function enrich(Place $place, ?int $userId = null): BusinessEnrichmentResult
    {
        /** @var array<string, mixed> $merged */
        $merged = [];
        /** @var array<string, string> $wonBy  field => id() of the source that first filled it */
        $wonBy = [];
        /** @var list<array<string, mixed>> $galleryContributions */
        $galleryContributions = [];
        $statuses = [];

        foreach ($this->sources as $source) {
            try {
                $patch = $source->enrich($place);

                // `gallery_json` is UNION-merged across sources (ordering/dedup/cap
                // applied once at the end), not first-non-empty-wins like the
                // scalar fields — else the first source's gallery would block the
                // rest. Pull it out before the per-field merge below.
                if (is_array($patch['gallery_json'] ?? null)) {
                    $galleryContributions = array_merge($galleryContributions, array_values($patch['gallery_json']));
                }
                unset($patch['gallery_json']);

                foreach ($patch as $field => $value) {
                    if (! array_key_exists($field, $merged) && $value !== null && $value !== '' && $value !== []) {
                        $merged[$field] = $value;
                        $wonBy[$field] = $source->id();
                    }
                }
                $statuses[] = ['source' => $source->id(), 'status' => 'ok', 'fields' => array_keys($patch)];
            } catch (Throwable $e) {
                report($e);
                $statuses[] = ['source' => $source->id(), 'status' => 'failed', 'fields' => []];
            }
        }

        // Build the ordered gallery (website-owned → business-attributed Google →
        // fill → reel fallback) and derive the hero from its first entry. Both go
        // through PlaceEditor below, so a human-locked gallery/hero still wins.
        if ((bool) config('places.enrich.gallery.enabled', true)) {
            // The reel thumbnail is a last-resort ONLY when the place has no gallery
            // yet — otherwise a run where every source failed transiently would
            // otherwise downgrade an existing [w1,w2,w3] gallery to [reel].
            $reelFallback = empty($place->gallery_json) ? $place->thumbnail_url : null;
            // A lone-locked hero must stay gallery[0] (else the carousel's first
            // image diverges from the manually-pinned image_url).
            $pinnedHero = $place->isFieldLocked('image_url') ? $place->image_url : null;

            $gallery = $this->gallery->build($place, $galleryContributions, $reelFallback, $pinnedHero);

            // Only write when a real business photo was found, or the place had no
            // gallery yet (first population) — never clobber an existing gallery
            // with a reel-only fallback from a failed run. Reel always sorts last,
            // so a non-reel gallery[0] means a real photo was found.
            $hasReal = $gallery !== [] && $gallery[0]['source'] !== 'reel';
            if ($gallery !== [] && ($hasReal || empty($place->gallery_json))) {
                // NOTE: the image_url ↔ gallery[0] mirror is derived from this
                // pre-lock snapshot; PlaceEditor re-fetches under lockForUpdate
                // (T-085). If a manual image_url lock commits during this run's I/O
                // window, the hero and gallery[0] can briefly diverge — cosmetic,
                // and the next enrichment (seeing the lock) re-pins them.
                $merged['gallery_json'] = $gallery;
                $merged['image_url'] = $gallery[0]['url'];
            }
        }

        // Apply respecting locks + audit (no-op when nothing new/unlocked changed),
        // and record that a run happened even if nothing changed. The whole write
        // is guarded so a persistence error (e.g. a bad scraped value) still
        // degrades gracefully — enrich() must never throw.
        // A contact field is provider-verified only when Google's API supplied it
        // (T-117 / SEC-1). `WebsiteBusinessSource` scrapes `places.website`, which
        // for an unclaimed pin is a URL the sharer nominated — a phone read from
        // its JSON-LD is claimant-controllable, so it must NOT earn a `google`
        // stamp. Carry the winning source per contact field to the editor.
        $contactSources = [];
        foreach (['website', 'phone'] as $contactField) {
            if (array_key_exists($contactField, $merged)) {
                $contactSources[$contactField] = ($wonBy[$contactField] ?? null) === GoogleBusinessSource::SOURCE_ID
                    ? ContactFieldSource::Google
                    : ContactFieldSource::Extraction;
            }
        }

        $edit = null;
        try {
            $edit = $this->editor->apply($place, $merged, PlaceEdit::ORIGIN_ENRICHMENT, $userId, contactSources: $contactSources);
            $place->forceFill(['enriched_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
            $statuses[] = ['source' => 'persist', 'status' => 'failed', 'fields' => array_keys($merged)];
        }

        return new BusinessEnrichmentResult($statuses, $edit);
    }
}
