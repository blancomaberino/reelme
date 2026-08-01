<?php

use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Models\SourcePost;
use App\Services\Ingestion\SourcePostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Share → `source_posts` resolution (T-109).
 *
 * This was ~70 lines inside `ShareController::store()`, reachable only through
 * an authenticated HTTP request with a full share payload — so the branches
 * that matter least often (a disabled platform, an over-long canonical URL, an
 * unknown host) were exercised end-to-end or not at all. Each is now a direct
 * call.
 */
uses(TestCase::class, RefreshDatabase::class);

function resolver(): SourcePostResolver
{
    return app(SourcePostResolver::class);
}

describe('branch 1 — a pasted caption', function () {
    it('stores the caption pre-fetched so the pipeline skips the fetch', function () {
        $resolved = resolver()->resolve(null, null, 'Best milanesa in Montevideo');

        expect($resolved->post->caption)->toBe('Best milanesa in Montevideo')
            // Pre-fetched: FetchSourcePost no-ops and extraction reads the text.
            ->and($resolved->post->fetch_status)->toBe(FetchStatus::Fetched)
            ->and($resolved->post->fetched_at)->not->toBeNull()
            ->and($resolved->post->url)->toStartWith('manual://');
    });

    it('keeps a supplied URL as a reference but still reads the caption', function () {
        $resolved = resolver()->resolve('https://www.instagram.com/reel/ABC123/', null, 'A caption');

        expect($resolved->post->caption)->toBe('A caption')
            ->and($resolved->post->url)->toContain('instagram.com')
            ->and($resolved->post->fetch_status)->toBe(FetchStatus::Fetched);
    });

    it('truncates a reference URL to the column width rather than 500ing', function () {
        $long = 'https://example.com/'.str_repeat('a', 3000);

        expect(mb_strlen(resolver()->resolve($long, null, 'caption')->post->url))->toBe(2048);
    });

    it('mints a fresh id per submission, so the dedup guard deliberately cannot fire', function () {
        // Documented trade-off: resubmitting a caption creates a new run and pin.
        // ResolvePlace still dedups the resulting PLACE by geo + name.
        $a = resolver()->resolve(null, null, 'same text');
        $b = resolver()->resolve(null, null, 'same text');

        expect($a->post->id)->not->toBe($b->post->id)
            ->and($a->post->external_id)->not->toBe($b->post->external_id);
    });

    it('reports the hinted platform, defaulting the stored one to instagram', function () {
        $hinted = resolver()->resolve(null, 'tiktok', 'text');
        expect($hinted->platform)->toBe(Platform::Tiktok);

        $unhinted = resolver()->resolve(null, null, 'text');
        expect($unhinted->platform)->toBeNull()
            ->and($unhinted->post->platform)->toBe(Platform::Instagram);
    });

    it('ignores a hint that is not a real platform', function () {
        expect(resolver()->resolve(null, 'myspace', 'text')->platform)->toBeNull();
    });
});

describe('branch 2 — a post URL', function () {
    it('converges two shares of the same post on one row', function () {
        $first = resolver()->resolve('https://www.instagram.com/reel/ABC123/', null);
        $second = resolver()->resolve('https://www.instagram.com/reel/ABC123/?utm_source=ig_web', null);

        // Canonicalization strips the tracking param, so both land on the same
        // (platform, external_id) — this is what makes the share dedup work.
        expect($second->post->id)->toBe($first->post->id)
            ->and(SourcePost::count())->toBe(1);
    });

    it('reports the platform it actually recognised', function () {
        $resolved = resolver()->resolve('https://www.instagram.com/reel/ABC123/', null);

        expect($resolved->platform)->toBe(Platform::Instagram)
            ->and($resolved->post->fetch_status)->not->toBe(FetchStatus::Fetched);
    });

    /**
     * The launch gate (T-014). Previously only reachable by POSTing a share.
     */
    it('rejects a recognised but disabled platform with a usable message', function () {
        config()->set('ingestion.platforms.tiktok.enabled', false);

        expect(fn () => resolver()->resolve('https://www.tiktok.com/@a/video/123', null))
            ->toThrow(ValidationException::class);

        try {
            resolver()->resolve('https://www.tiktok.com/@a/video/123', null);
        } catch (ValidationException $e) {
            expect($e->errors()['url'][0])->toContain('TikTok')->toContain('only Instagram');
        }

        expect(SourcePost::count())->toBe(0); // nothing half-created
    });

    it('accepts the same platform once it is switched on', function () {
        config()->set('ingestion.platforms.tiktok.enabled', true);

        expect(resolver()->resolve('https://www.tiktok.com/@a/video/123', null)->platform)
            ->toBe(Platform::Tiktok);
    });

    /**
     * `source_posts.url` is varchar(2048). Without this guard Postgres raises
     * 22001 and the request 500s; the URL can exceed the request validation
     * because it may come out of `shared_text` (max 5000) or a shortlink
     * expansion.
     */
    it('rejects an over-long canonical URL as a 422, not a 500', function () {
        $long = 'https://www.instagram.com/reel/'.str_repeat('a', 2100).'/';

        expect(fn () => resolver()->resolve($long, null))
            ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(422));

        expect(SourcePost::count())->toBe(0);
    });

    /**
     * The data-model gap recorded in ADR-109: `source_posts.platform` is NOT
     * NULL with four fixed values, so an unknown host has to be stored under a
     * placeholder — but the placeholder must never be reported as fact.
     */
    it('stores a placeholder platform for an unknown host but reports null', function () {
        $resolved = resolver()->resolve('https://some-food-blog.example/post/42', null);

        expect($resolved->post->platform)->toBe(Platform::Instagram) // placeholder
            ->and($resolved->platform)->toBeNull();                  // the truth
    });

    it('prefers the hint over the instagram default for that placeholder', function () {
        $resolved = resolver()->resolve('https://some-food-blog.example/post/42', 'youtube');

        expect($resolved->post->platform)->toBe(Platform::Youtube)
            ->and($resolved->platform)->toBeNull();
    });

    it('derives a stable external id from the URL when the host offers none', function () {
        $a = resolver()->resolve('https://some-food-blog.example/post/42', null);
        $b = resolver()->resolve('https://some-food-blog.example/post/42', null);

        // Stable, so re-sharing an unknown-host link still dedups.
        expect($b->post->id)->toBe($a->post->id)
            ->and($a->post->external_id)->toBe(sha1($a->post->url));
    });
});

describe('branch 3 — no URL and no caption', function () {
    it('creates a manual placeholder awaiting the review screen', function () {
        $resolved = resolver()->resolve(null, null);

        expect($resolved->post->fetch_status)->toBe(FetchStatus::Manual)
            ->and($resolved->post->url)->toStartWith('manual://')
            ->and($resolved->post->caption)->toBeNull()
            // Null, not the stored placeholder: there is no known platform yet.
            ->and($resolved->platform)->toBeNull();
    });

    it('honours a hint for the stored platform while still reporting null', function () {
        $resolved = resolver()->resolve(null, 'tiktok');

        expect($resolved->post->platform)->toBe(Platform::Tiktok)
            ->and($resolved->platform)->toBeNull();
    });

    it('never collides with another manual placeholder', function () {
        expect(resolver()->resolve(null, null)->post->external_id)
            ->not->toBe(resolver()->resolve(null, null)->post->external_id);
    });
});

describe('branch priority', function () {
    it('lets a caption win over a URL', function () {
        // Order matters: a share carrying both is a manual paste that happens to
        // reference a link, not a post to fetch.
        $resolved = resolver()->resolve('https://www.instagram.com/reel/ABC123/', null, 'pasted text');

        expect($resolved->post->caption)->toBe('pasted text')
            ->and($resolved->post->fetch_status)->toBe(FetchStatus::Fetched);
    });
});
