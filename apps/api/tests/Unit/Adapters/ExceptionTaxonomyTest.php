<?php

use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\NeedsManualFallback;
use App\Adapters\Exceptions\PostUnavailable;

it('maps each adapter exception to a failure code', function () {
    expect((new FetchFailed)->failureCode())->toBe('fetch_unavailable')
        ->and((new PostUnavailable)->failureCode())->toBe('fetch_unavailable')
        ->and((new PostUnavailable('gone', requiresAuth: true))->failureCode())->toBe('fetch_auth_required')
        ->and((new NeedsManualFallback)->failureCode())->toBe('fetch_unavailable');
});

it('carries a Retry-After on transient fetch failures', function () {
    expect((new FetchFailed('rate limited', retryAfter: 42))->retryAfter)->toBe(42)
        ->and((new FetchFailed)->retryAfter)->toBeNull();
});
