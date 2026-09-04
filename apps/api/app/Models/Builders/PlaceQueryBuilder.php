<?php

namespace App\Models\Builders;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\Dish;
use App\Models\HiddenPlace;
use App\Models\Influencer;
use App\Models\Offer;
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
     * Places serving a matching dish (T-157) — the filter behind "five places
     * near me that do pasta", which neither `cuisine_primary` (`italian`) nor the
     * vibe chips can answer.
     *
     * Matches the normalized dish text as a SUBSTRING, so `?dish=pasta` finds
     * "Pasta al pesto", "Pastas caseras" and "Lasagna de pasta". That favours
     * recall over precision on purpose — a discovery filter that misses the
     * plural of the word someone typed is worse than one that occasionally
     * offers a near neighbour.
     *
     * Case- and accent-insensitivity comes from both sides reducing through
     * {@see Dish::normalizeName()}; because that leaves only `[a-z0-9 ]`, the
     * needle cannot smuggle a LIKE wildcard into the pattern, and it reaches
     * Postgres as a bound parameter regardless.
     *
     * Two floors, both of which must be here rather than only in the request —
     * this builder is callable from anywhere, and a guard that lives only in a
     * FormRequest protects only the callers that happen to use one:
     *
     * - **{@see Dish::MIN_QUERY}**, applied to the NORMALIZED needle. Below three
     *   characters pg_trgm extracts no trigram, `dishes_name_normalized_trgm`
     *   goes unused, and the filter becomes a sequential scan of every dish we
     *   know on a public route. `?dish=p.` clears a raw-string `min:` and reduces
     *   to one character, which is exactly why the check is on the needle.
     * - **A term that normalizes away entirely** (`?dish=!!!`) matches NOTHING
     *   rather than falling through. A filter that silently becomes "every place"
     *   when it cannot understand its input is worse than an empty result: the
     *   caller believes they filtered.
     *
     * Only PUBLISHED sources count. `ShareModerator::takeDown()` nulls
     * `published_at` without deleting the source, so without this predicate a
     * contribution moderation pulled — a DMCA removal included — would stay
     * SEARCHABLE, and `?dish=` would be a corpus-wide oracle for finding it.
     *
     * Search is the half this closes, and the distinction is worth stating
     * precisely rather than implying the stronger claim: `PlaceController::show()`
     * loads a place's sources WITHOUT a `published()` filter, so a taken-down
     * source's dish names still render for someone who already has the place's
     * id. That is unchanged, pre-existing behaviour — it was equally true when
     * these dishes were read out of the snapshot — and closing it means gating
     * the whole source load, which also moves attribution and tags. That belongs
     * in its own task, not smuggled in behind a filter.
     * What the gate does NOT do — stated because the first version of this
     * comment claimed the opposite, and a false premise beside a guard is what
     * stops the next reader checking: it does not keep this filter agreeing with
     * its older twin. Dish names are ALSO materialized as `TagKind::Dish` tags,
     * and `ShareModerator::takeDown()` nulls `published_at` WITHOUT re-running
     * `TagMaterializer` (`PlacePublisher::recountCounters()` recomputes counters
     * only). So after a take-down `?dish=milanesa` correctly returns nothing
     * while `?tags[]=<the dish slug>` still returns the place. The gate makes
     * THIS filter honest; the tag path's retraction is a separate, pre-existing
     * gap, and closing it means making take-down re-materialize tags.
     *
     * A BLOCKED account's source is deliberately NOT excluded here, though it is
     * excluded from the place detail's source load. Review proposed adding it;
     * {@see App\Services\Moderation\BlockUsers} forbids it in as many words —
     * "WHAT IS DELIBERATELY NOT AFFECTED: places … dropping a whole restaurant
     * off the map because one blocked account also shared it would punish the
     * blocker. Their ATTRIBUTION is hidden from the blocker's view; the pin
     * stays." A dish-derived match is the pin, not the attribution. So a blocker
     * can match a place on a dish whose claim they will not see credited, and
     * that is the policy working, not a leak.
     */
    public function servingDish(string $dish): self
    {
        $needle = Dish::normalizeName($dish);

        if (mb_strlen($needle) < Dish::MIN_QUERY) {
            return $this->whereRaw('false');
        }

        $this->whereExists(fn ($sub) => $sub->from('dishes')
            ->join('place_sources', 'place_sources.id', '=', 'dishes.place_source_id')
            ->whereColumn('place_sources.place_id', 'places.id')
            ->whereNotNull('place_sources.published_at')
            ->where('dishes.name_normalized', 'like', '%'.$needle.'%'));

        return $this;
    }

    /**
     * Places offering a payment discount for the given card/bank/wallet (T-079).
     * Matches a place_source snapshot whose `discounts[]` carries the token as its
     * resolved issuer, scheme, or `@handle` — the SAME label
     * `PlaceAggregations::discountCard()` computes for display, so the map/index
     * filter and the shown chips agree. Case-insensitive; a blank token is a no-op.
     */
    /**
     * Select `has_active_offer` — whether the place has something redeemable
     * RIGHT NOW (T-042 badge, T-047 filter).
     *
     * One EXISTS subquery for the whole page, never a query per row: the map
     * draws hundreds of pins at once, so this must not become an N+1.
     *
     * Gated on the `active()` scope rather than the status column, because
     * nothing rewrites that column when a window closes overnight — a badge
     * built on the column alone promises an offer the till would refuse.
     */
    /**
     * Metres from a point, as PostGIS measures it on the `geography` column.
     *
     * PRIVATE, and paired with its bindings by {@see self::distanceFrom()}. The
     * expression is needed in two grammatical positions — aliased into a select
     * list, and unaliased in the ORDER BY and keyset predicate of
     * `sort=distance`, since Postgres will not accept a select alias in a WHERE
     * — so it cannot simply be hidden inside one method. But exposing the SQL
     * without the bindings shares the harmless half and leaves the dangerous
     * half to be retyped: the order is `[lng, lat]`, the reverse of how every
     * other line in this codebase says a coordinate, because that is the
     * argument order `ST_MakePoint` takes. Getting it backwards measures from a
     * mirrored point — a plausible ordering, no error, and no red test unless
     * the fixture's coordinates are asymmetric.
     */
    private const DISTANCE_SQL = 'ST_Distance(location, ST_MakePoint(?, ?)::geography)';

    /**
     * The distance expression and its bindings, together, for a caller that
     * needs to place it itself (an ORDER BY, a keyset comparison).
     *
     * @param  array{lat: float, lng: float}  $near
     * @return array{string, array{float, float}}
     */
    public static function distanceFrom(array $near): array
    {
        return [self::DISTANCE_SQL, [$near['lng'], $near['lat']]];
    }

    /**
     * Select metres from `$near` as `distance`.
     *
     * Call it AFTER any `select()`: `select()` REPLACES the select list while
     * `selectRaw()` appends, so adding the distance first silently drops it and
     * every row reports 0 — which is not null, so a `not->toBeNull()` test would
     * have passed.
     *
     * @param  array{lat: float, lng: float}  $near
     */
    public function withDistanceFrom(array $near): self
    {
        [$sql, $bindings] = self::distanceFrom($near);

        return $this->selectRaw($sql.' AS distance', $bindings);
    }

    public function withActiveOfferFlag(): self
    {
        return $this->withExists(['offers as has_active_offer' => self::onlyActiveOffers(...)]);
    }

    /** Narrow to places running an offer right now — the same gate as above. */
    public function havingActiveOffer(): self
    {
        return $this->whereHas('offers', self::onlyActiveOffers(...));
    }

    /**
     * The single definition of "an offer you could redeem right now", shared by
     * the badge and the filter so they cannot drift apart.
     *
     * A named method rather than an inline closure purely so the relation's
     * builder can be typed — `Offer::scopeActive()` is invisible to static
     * analysis through a bare `fn ($q)`.
     *
     * @param  Builder<Offer>  $query
     */
    private static function onlyActiveOffers(Builder $query): void
    {
        $query
            ->active()
            // ...and not SOLD OUT. `active()` answers "live right now" from the
            // status and window alone, which is the right question for T-043's
            // issue gate but not for a badge: an offer whose lifetime quota is
            // spent is live and un-redeemable, so advertising it sends someone
            // to a counter for a refusal.
            //
            // The per-USER and per-DAY quotas deliberately stay out of this.
            // Both depend on who is asking and on today's count, so they cannot
            // be a shared, cacheable property of the place — this flag means
            // "this venue has an offer running", not "you personally may redeem
            // it". {@see Offer::isRedeemable()} is still the only gate on
            // issuing.
            ->notSoldOut();
    }

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
     * Places an INFLUENCER is credited on — their posts, on published shares.
     *
     * The direct sibling of {@see publishedBy()}, and it exists for the same
     * reason that one does: the influencer profile's counter, its map, and its
     * places list are three views of one question, and until this scope existed
     * each had its own copy of the answer. They disagreed — a profile reading
     * "2 Lugares" one tap above a map saying "no places from this creator".
     *
     * Note the shape: `sources` joins `place_sources`, whose `source_post` is
     * where the influencer credit lives, while the PUBLISHED test is on the
     * share. A place is credited when SOME source satisfies both at once, which
     * is why this is one whereHas over the pair and not two.
     */
    public function promotedBy(Influencer $influencer): self
    {
        $this->whereHas(
            'sources',
            fn ($q) => $q
                ->whereHas('sourcePost', fn ($p) => $p->where('influencer_id', $influencer->id))
                ->whereHas('share', fn ($sh) => $sh->where('status', ShareStatus::Published)),
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
