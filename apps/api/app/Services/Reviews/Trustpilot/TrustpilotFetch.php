<?php

namespace App\Services\Reviews\Trustpilot;

/**
 * The outcome of a Trustpilot fetch (T-082), so the refresher can tell three
 * cases apart without the client ever throwing:
 *
 * - `resolved`   — a business was found; `result` carries its summary → upsert.
 * - `empty`      — the API answered, but no business resolved for the domain →
 *                  drop any stale cached row (the source genuinely has nothing).
 * - `unavailable`— the fetch could not be completed (disabled/unkeyed, network,
 *                  timeout, non-2xx) → KEEP the existing row; a transient outage
 *                  must not blank a still-recent cached summary.
 */
final readonly class TrustpilotFetch
{
    private function __construct(
        public string $status,
        public ?TrustpilotResult $result = null,
    ) {}

    public static function resolved(TrustpilotResult $result): self
    {
        return new self('resolved', $result);
    }

    public static function empty(): self
    {
        return new self('empty');
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }
}
