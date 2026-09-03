<?php

namespace App\Support;

/**
 * How many cached provider review snippets a place payload may carry (02 §3.8).
 *
 * A neutral leaf, for the same reason as {@see CsvList}: the writers
 * (`GooglePlacesGeocoder::reviews()`, `TrustpilotClient`), the read boundaries
 * (`PlaceResource::googleReviewsForResource()`,
 * `App\Services\Reviews\ReviewSnippet::listFromArray()`) and the contract all
 * need this number, and none of them may depend on another.
 *
 * It was a bare `5` in four separate files, and the count is the reason this
 * class exists rather than a constant on whichever class happened to need it
 * first. Reviewing T-128 found `place.json` advertising "at most 5" while
 * enforcing nothing, then — once `google_reviews` was capped — found
 * `review_sources[].snippets` serving the SAME `google_reviews_json` column
 * through a second path with no cap at all. Four copies of a rule is three
 * copies that can be missed, and one of them already had been.
 */
final class CachedReviews
{
    public const MAX = 5;
}
