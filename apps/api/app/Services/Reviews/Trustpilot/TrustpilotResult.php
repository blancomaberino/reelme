<?php

namespace App\Services\Reviews\Trustpilot;

use App\Services\Reviews\ReviewSnippet;

/**
 * A fetched Trustpilot business-unit summary (T-082): the TrustScore (0–5), the
 * number of reviews, the public review-page url, and up to a handful of
 * normalized snippet excerpts. Produced by {@see TrustpilotClient} and persisted
 * by {@see TrustpilotReviewRefresher}.
 */
final readonly class TrustpilotResult
{
    /**
     * @param  list<ReviewSnippet>  $snippets
     */
    public function __construct(
        public ?float $rating,
        public int $count,
        public ?string $url,
        public array $snippets = [],
    ) {}
}
