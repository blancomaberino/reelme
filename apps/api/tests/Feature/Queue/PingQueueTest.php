<?php

use App\Jobs\PingQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('dispatches PingQueue onto the named ingest queue', function () {
    Queue::fake();

    PingQueue::dispatch(Str::uuid()->toString())->onQueue('ingest');

    Queue::assertPushedOn('ingest', PingQueue::class);
});

it('writes its cache key when executed synchronously', function () {
    $uuid = Str::uuid()->toString();

    Bus::dispatchSync(new PingQueue($uuid));

    expect(Cache::get(PingQueue::cacheKey($uuid)))->not->toBeNull();
});
