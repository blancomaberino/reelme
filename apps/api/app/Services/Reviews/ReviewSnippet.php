<?php

namespace App\Services\Reviews;

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
     * @return list<self>
     */
    public static function listFromArray(mixed $rows): array
    {
        return array_map(
            self::fromArray(...),
            array_values(array_filter(is_array($rows) ? $rows : [], is_array(...))),
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
