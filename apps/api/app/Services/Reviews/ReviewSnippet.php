<?php

namespace App\Services\Reviews;

use App\Support\CachedReviews;

/**
 * One normalized review excerpt, source-agnostic (T-082). Every provider — the
 * cached Google snippets, Trustpilot, a future source — maps its own payload to
 * this shape so the client renders a single, uniform snippet regardless of where
 * it came from. `rating` is 1–5 on the source's own scale (already normalized to
 * 5 by the driver); a null rating means the source did not carry one.
 */
final readonly class ReviewSnippet
{
    public function __construct(
        public ?string $author,
        public ?float $rating,
        public ?string $text,
        public ?string $relativeTime = null,
        public ?string $profilePhotoUrl = null,
    ) {}

    /**
     * Build from a loosely-typed cached array (e.g. `google_reviews_json` rows or
     * a persisted Trustpilot snippet), guarding every key — cached/legacy rows
     * routinely omit fields. Only http(s) photo URLs survive; anything else nulls
     * out so the client never renders a broken/again-fetched image.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $photo = $data['profile_photo_url'] ?? null;

        return new self(
            // Clamp to the contract's 0–5 range so a dirty/legacy cached row can
            // never emit a snippet rating that violates the place schema.
            author: self::str($data['author'] ?? null),
            rating: isset($data['rating']) && is_numeric($data['rating'])
                ? max(0.0, min(5.0, (float) $data['rating']))
                : null,
            text: self::str($data['text'] ?? null),
            relativeTime: self::str($data['relative_time'] ?? null),
            profilePhotoUrl: is_string($photo) && preg_match('#^https?://#i', $photo) === 1 ? $photo : null,
        );
    }

    /**
     * Normalize a cached rows array — `google_reviews_json`, a persisted
     * Trustpilot snapshot, any external driver's stored snippets — to a snippet
     * list, skipping non-array rows. The shared decode both external drivers use.
     *
     * CAPPED at {@see CachedReviews::MAX}, and this is the reason the cap lives
     * at a decode rather than at one caller. Reviewing T-128 capped
     * `google_reviews` in `PlaceResource` and left this path alone — but
     * `GoogleReviewSource` reads THE SAME `google_reviews_json` column through
     * here into `review_sources[].snippets`, so a six-row legacy column stayed
     * fully served through the second door while the first one was shut. Every
     * driver's snippets go through this function; capping here is what makes
     * the contract's `maxItems` true for all of them.
     *
     * The cap is a PARAMETER with the contract's value as its default, not a
     * constant buried in the decode: capped-by-default is the behaviour every
     * caller wants, but a future one that legitimately needs the whole set (an
     * admin export, a moderation view) should be able to say so at the call
     * site rather than discover the limit from in here.
     *
     * @return list<self>
     */
    public static function listFromArray(mixed $rows, int $limit = CachedReviews::MAX): array
    {
        return array_map(
            self::fromArray(...),
            array_slice(
                array_values(array_filter(is_array($rows) ? $rows : [], is_array(...))),
                0,
                $limit,
            ),
        );
    }

    /**
     * @return array{author: string|null, rating: float|null, text: string|null, relative_time: string|null, profile_photo_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'author' => $this->author,
            'rating' => $this->rating,
            'text' => $this->text,
            'relative_time' => $this->relativeTime,
            'profile_photo_url' => $this->profilePhotoUrl,
        ];
    }

    /** Trim to a non-empty string, else null — blank strings read as "absent". */
    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
