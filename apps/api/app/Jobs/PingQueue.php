<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Trivial queue-plumbing smoke job: writes a cache key when it runs. Reused by
 * queue/health smoke tests — keep it.
 */
class PingQueue implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $uuid) {}

    public static function cacheKey(string $uuid): string
    {
        return "queue:ping:{$uuid}";
    }

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->uuid), now()->toIso8601ZuluString(), 300);
    }
}
