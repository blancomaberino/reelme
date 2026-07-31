<?php

namespace App\Models\Builders;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The Place query vocabulary (T-106), moved off the model.
 *
 * These were six `scopeX()` methods on `Place`, which is 40% of why that model
 * was the highest-degree node in the codebase: every one of them is a query
 * concern, not a persistence or relationship concern. As real methods on a
 * dedicated builder the call sites are unchanged — `Place::query()
 * ->publiclyVisible()` still reads exactly the same — but they are now
 * discoverable in one file and typed rather than magic.
 *
 * Returned from {@see Place::newEloquentBuilder()}, so every `Place` query
 * starts here.
 *
 * @extends Builder<Place>
 */
class PlaceQueryBuilder extends Builder
{
    /**
     * Places visible on public read surfaces (map, browse index): pending +
     * active — a first auto-publish is on the map immediately (02 §3.8, the
     * documented deviation from "active only") — never merged tombstones.
     */
    public function publiclyVisible(): self
    {
        $this->whereIn('status', PlaceStatus::matchable())
            ->whereNull('merged_into_place_id');

        return $this;
    }

    /**
     * Places carrying ALL of the given tag slugs (AND) — each selected tag
     * narrows the results, the way a multi-select filter is expected to (picking
     * more tags returns fewer, not more, places). One EXISTS per distinct slug,
     * every one required. The place_tag pivot lands in T-031 — until it exists
     * this is a validated no-op (schema-guarded), so both the map and the browse
     * index accept tags[] today.
     *
     * @param  list<string>  $slugs
     */
    public function allTagSlugs(array $slugs): self
    {
        if ($slugs === [] || ! Schema::hasTable('place_tag')) {
            return $this;
        }

        foreach (array_unique($slugs) as $slug) {
            $this->whereExists(fn ($sub) => $sub->from('place_tag')
                ->join('tags', 'tags.id', '=', 'place_tag.tag_id')
                ->whereColumn('place_tag.place_id', 'places.id')
                ->where('tags.slug', $slug));
        }

        return $this;
    }

    /**
     * Places offering a payment discount for the given card/bank/wallet (T-079).
     * Matches a place_source snapshot whose `discounts[]` carries the token as its
     * resolved issuer, scheme, or `@handle` — the SAME label
     * `PlaceAggregations::discountCard()` computes for display, so the map/index
     * filter and the shown chips agree. Case-insensitive; a blank token is a no-op.
     */
    public function withPaymentCard(string $card): self
    {
        $card = mb_strtolower(trim($card));
        if ($card === '') {
            return $this;
        }

        $this->whereExists(fn ($sub) => $sub->from('place_sources')
            ->whereColumn('place_sources.place_id', 'places.id')
            ->whereRaw(
                'EXISTS (SELECT 1 FROM jsonb_array_elements('.Place::DISCOUNTS_JSONB.
                ') AS d WHERE lower('.Place::DISCOUNT_CARD_SQL.') = ? AND '.Place::DISCOUNT_HAS_TERMS.')',
                [$card],
            ));

        return $this;
    }

    /**
     * Places traceable to accounts the user follows (T-037): a place_source
     * whose share belongs to a followed user, or whose source post is
     * credited to a followed influencer.
     */
    public function followedBy(User $user): self
    {
        $this->whereExists(fn ($sub) => $sub->from('place_sources')
            ->join('shares', 'shares.id', '=', 'place_sources.share_id')
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->whereColumn('place_sources.place_id', 'places.id')
            // Attribution only through PUBLISHED shares — a resolved-but-
            // failed share must not whisper "someone you follow was here".
            ->where('shares.status', ShareStatus::Published->value)
            ->where(fn ($w) => $w
                ->whereIn('shares.user_id', fn ($f) => $f->select('followee_id')->from('follows')
                    ->where('follower_user_id', $user->id)->where('followee_type', 'user'))
                ->orWhereIn('source_posts.influencer_id', fn ($f) => $f->select('followee_id')->from('follows')
                    ->where('follower_user_id', $user->id)->where('followee_type', 'influencer'))));

        return $this;
    }

    /**
     * Places evidenced by a user's PUBLISHED shares (T-036/T-071) — the public
     * subset behind their profile map and places list. Sibling to {@see mine()};
     * shared by ProfileController::map() and places() so the two views can never
     * disagree on what "their published places" means.
     */
    public function publishedBy(User $user): self
    {
        $this->whereHas(
            'sources.share',
            fn ($s) => $s->where('user_id', $user->id)->where('status', ShareStatus::Published),
        );

        return $this;
    }

    /**
     * The caller's personal collection (T-071, ADR-071): a place is "mine" when
     * I published a share resolving to it, OR I saved it to one of my lists —
     * AND I have not soft-hidden that specific pin. A query scope over the
     * canonical (global, deduped) places; saving another user's place makes it
     * mine. The hide is per-PLACE ({@see HiddenPlace}), so removing one pin of a
     * multi-place post leaves its siblings — the earlier per-share dismissal hid
     * every place the share resolved to.
     */
    public function mine(User $user): self
    {
        $this
            // Not a pin I've removed from my map (per-place soft-hide).
            ->whereNotExists(fn ($h) => $h->from('hidden_places')
                ->whereColumn('hidden_places.place_id', 'places.id')
                ->where('hidden_places.user_id', $user->id))
            ->where(fn (Builder $w) => $w
                // Shared by me through a published share.
                ->whereExists(fn ($sub) => $sub->from('place_sources')
                    ->join('shares', 'shares.id', '=', 'place_sources.share_id')
                    ->whereColumn('place_sources.place_id', 'places.id')
                    ->where('shares.user_id', $user->id)
                    ->where('shares.status', ShareStatus::Published->value))
                // OR saved to one of my lists.
                ->orWhereExists(fn ($sub) => $sub->from('place_list_items')
                    ->join('place_lists', 'place_lists.id', '=', 'place_list_items.place_list_id')
                    ->whereColumn('place_list_items.place_id', 'places.id')
                    ->where('place_lists.user_id', $user->id)));

        return $this;
    }
}
