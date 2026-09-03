<?php

namespace Tests;

use App\Services\Geo\NullTimezoneResolver;
use App\Services\Geo\TimezoneResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * No test may reach the Google Time Zone API (NFR-15, and T-155's own
         * lookup is separately BILLED). The container would otherwise hand out
         * the real resolver in any environment that has a `GOOGLE_PLACES_API_KEY`
         * — which a developer's `.env` does — so enrichment tests would quietly
         * spend money, and would fail in CI where there is no network.
         *
         * The default is therefore the null resolver, which is also the honest
         * production behaviour of an unconfigured install: no timezone, and
         * consequently no open/closed cue. A test that wants a timezone binds its
         * own fake, or writes the column directly.
         */
        $this->app->instance(TimezoneResolver::class, new NullTimezoneResolver);
    }
}
