<?php

use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\YtDlpAdapter;
use App\Enums\MediaKind;
use App\Enums\Platform;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function ytPost(string $url = 'https://www.instagram.com/reel/ABC123/'): SourcePostData
{
    return new SourcePostData(
        platform: Platform::Instagram,
        externalId: 'ABC123',
        url: $url,
        caption: 'best tacos in lisbon',
    );
}

it('downloads a reel to a local video file and returns it as a Video FetchedMedia', function () {
    // yt-dlp prints the final path (`after_move:filepath`) on stdout.
    Process::fake(['*' => Process::result(output: "/tmp/reel_ytdlp_abc.mp4\n")]);

    $result = (new YtDlpAdapter)->fetchMedia(ytPost(), null);

    expect($result->media)->toHaveCount(1);
    $media = $result->media[0];
    expect($media->kind)->toBe(MediaKind::Video)
        ->and($media->localPath)->toBe('/tmp/reel_ytdlp_abc.mp4')
        ->and($media->url)->toBeNull()
        ->and($media->mime)->toBe('video/mp4');
});

it('builds a safe array command: download best video, print path, no-simulate, -- before the url', function () {
    Process::fake(['*' => Process::result(output: "/tmp/reel_ytdlp_x.mp4\n")]);

    (new YtDlpAdapter)->fetchMedia(ytPost('https://www.instagram.com/reel/ZZZ/'), null);

    Process::assertRan(function ($process) {
        $cmd = $process->command;

        // Array command (no shell), url last, `--` immediately before it.
        expect($cmd)->toBeArray()
            ->and($cmd[0])->toBe('yt-dlp')
            ->and(end($cmd))->toBe('https://www.instagram.com/reel/ZZZ/')
            ->and($cmd[count($cmd) - 2])->toBe('--')
            ->and($cmd)->toContain('--no-playlist')
            ->and($cmd)->toContain('--quiet') // stdout carries only the printed path
            ->and($cmd)->toContain('--no-simulate') // download despite --print
            ->and($cmd)->toContain('after_move:filepath')
            ->and($cmd)->not->toContain('--cookies'); // no cookie file configured

        // -o template targets a unique temp stem with the real ext appended.
        $oIndex = array_search('-o', $cmd, true);
        expect($cmd[$oIndex + 1])->toEndWith('.%(ext)s')
            ->and($cmd[$oIndex + 1])->toContain('reel_ytdlp_');

        return true;
    });
});

it('passes --cookies only when a readable cookie file is configured', function () {
    $cookies = tempnam(sys_get_temp_dir(), 'ck_');
    file_put_contents($cookies, "# Netscape\n");
    Process::fake(['*' => Process::result(output: "/tmp/x.mp4\n")]);

    (new YtDlpAdapter(cookiesPath: $cookies))->fetchMedia(ytPost(), null);

    Process::assertRan(function ($process) use ($cookies) {
        $cmd = $process->command;
        $i = array_search('--cookies', $cmd, true);

        return $i !== false && $cmd[$i + 1] === $cookies;
    });
    @unlink($cookies);
});

it('falls through (empty) on an image post — "No video formats found" is a non-zero exit', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ERROR: No video formats found!', exitCode: 1)]);

    $result = (new YtDlpAdapter)->fetchMedia(ytPost(), null);

    expect($result->media)->toBe([]);
});

it('falls through (empty) on any non-zero exit (missing binary, auth wall)', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'HTTP Error 403', exitCode: 1)]);

    expect((new YtDlpAdapter)->fetchMedia(ytPost(), null)->media)->toBe([]);
});

it('sweeps a partial download left behind on a failure exit (no temp leak)', function () {
    // --no-part writes straight to the final name, so a mid-download failure can
    // leave `<stem>.<ext>` behind. Simulate that by writing the file from the
    // fake, then assert fetchMedia cleaned it up on the failure path.
    $leftover = null;
    Process::fake(['*' => function ($process) use (&$leftover) {
        $i = array_search('-o', $process->command, true);
        $stem = str_replace('.%(ext)s', '', $process->command[$i + 1]);
        $leftover = $stem.'.mp4';
        file_put_contents($leftover, 'partial-bytes');

        return Process::result(output: '', errorOutput: 'ERROR: interrupted', exitCode: 1);
    }]);

    $result = (new YtDlpAdapter)->fetchMedia(ytPost(), null);

    expect($result->media)->toBe([])
        ->and($leftover)->not->toBeNull()
        ->and(file_exists($leftover))->toBeFalse();
});

it('does not pass --cookies when the configured cookie file is missing', function () {
    Process::fake(['*' => Process::result(output: "/tmp/x.mp4\n")]);

    (new YtDlpAdapter(cookiesPath: '/no/such/cookies.txt'))->fetchMedia(ytPost(), null);

    Process::assertRan(fn ($process) => ! in_array('--cookies', $process->command, true));
});

it('never throws — a process failure (e.g. timeout) falls through to empty', function () {
    // A yt-dlp hang past the timeout throws; fetchMedia must swallow it.
    Process::fake(['*' => fn () => throw new RuntimeException('process timed out')]);

    expect((new YtDlpAdapter)->fetchMedia(ytPost(), null)->media)->toBe([]);
});

it('returns empty (no process run) when disabled', function () {
    Process::fake();

    expect((new YtDlpAdapter(enabled: false))->fetchMedia(ytPost(), null)->media)->toBe([]);
    Process::assertNothingRan();
});

it('returns empty (no process run) for a non-http url — argument-injection guard', function () {
    Process::fake();

    expect((new YtDlpAdapter)->fetchMedia(ytPost('manual://ABC'), null)->media)->toBe([]);
    expect((new YtDlpAdapter)->fetchMedia(ytPost('--exec=rm -rf /'), null)->media)->toBe([]);
    Process::assertNothingRan();
});

it('returns empty when yt-dlp succeeds but prints no path', function () {
    Process::fake(['*' => Process::result(output: "\n  \n")]);

    expect((new YtDlpAdapter)->fetchMedia(ytPost(), null)->media)->toBe([]);
});

it('maps the printed extension to a mime type', function () {
    foreach (['/tmp/x.webm' => 'video/webm', '/tmp/x.mov' => 'video/quicktime', '/tmp/x.mkv' => 'video/x-matroska', '/tmp/x.mp4' => 'video/mp4'] as $path => $mime) {
        Process::fake(['*' => Process::result(output: $path."\n")]);
        expect((new YtDlpAdapter)->fetchMedia(ytPost(), null)->media[0]->mime)->toBe($mime);
    }
});

it('supports() matches only http(s) urls on supported platform hosts', function () {
    $adapter = new YtDlpAdapter;

    expect($adapter->supports('https://www.instagram.com/reel/ABC/'))->toBeTrue()
        ->and($adapter->supports('https://vt.tiktok.com/xyz/'))->toBeTrue()
        ->and($adapter->supports('https://youtu.be/abc'))->toBeTrue()
        ->and($adapter->supports('https://m.youtube.com/watch?v=abc'))->toBeTrue()
        ->and($adapter->supports('http://instagram.com/reel/x/'))->toBeTrue()
        // Look-alike and unsupported hosts + non-http schemes are rejected.
        ->and($adapter->supports('https://instagram.com.evil.com/reel/x/'))->toBeFalse()
        ->and($adapter->supports('https://example.com/x'))->toBeFalse()
        ->and($adapter->supports('manual://ABC'))->toBeFalse();
});

it('fetchMetadata returns the caption, author and posted date from yt-dlp -J', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'id' => 'DavzhF8h2Ym',
        'title' => 'Video by el_encantador_de_burgas',
        'description' => 'En la 13 visitamos a @lagranburgerok',
        'channel' => 'el_encantador_de_burgas',
        'uploader' => 'El Encantador de 🍔',
        'timestamp' => 1783975505,
    ]))]);

    $data = (new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reels/DavzhF8h2Ym/', null);

    expect($data)->toBeInstanceOf(SourcePostData::class)
        ->and($data->platform)->toBe(Platform::Instagram)
        ->and($data->externalId)->toBe('DavzhF8h2Ym')
        ->and($data->caption)->toBe('En la 13 visitamos a @lagranburgerok') // description, not the generic title
        ->and($data->authorHandle)->toBe('el_encantador_de_burgas')
        ->and($data->authorDisplayName)->toBe('El Encantador de 🍔')
        ->and($data->postedAt?->timestamp)->toBe(1783975505)
        ->and($data->raw['source'])->toBe('ytdlp');

    // Metadata is a dump (-J), never a download.
    Process::assertRan(fn ($p) => in_array('-J', $p->command, true) && ! in_array('--no-simulate', $p->command, true));
});

it('fetchMetadata falls back to upload_date and the title when description is absent', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'id' => 'XYZ', 'title' => 'Best tacos in town', 'upload_date' => '20260713',
    ]))]);

    $data = (new YtDlpAdapter)->fetchMetadata('https://www.tiktok.com/@u/video/1', null);

    expect($data->caption)->toBe('Best tacos in town')
        ->and($data->platform)->toBe(Platform::Tiktok)
        // `!Ymd` resets time-of-day to midnight (without the `!`, posted_at would
        // inherit the current wall-clock time on the upload date).
        ->and($data->postedAt?->format('Y-m-d H:i:s'))->toBe('2026-07-13 00:00:00');
});

it('fetchMetadata leaves postedAt null when neither timestamp nor upload_date is present', function () {
    Process::fake(['*' => Process::result(output: json_encode(['id' => 'ABC', 'description' => 'x']))]);

    expect((new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reel/ABC/', null)->postedAt)->toBeNull();
});

it('fetchMetadata throws PostUnavailable when the JSON has no id (object present, id missing)', function () {
    Process::fake(['*' => Process::result(output: json_encode(['description' => 'no id here']))]);
    (new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reel/ABC/', null);
})->throws(PostUnavailable::class);

it('fetchMetadata passes --cookies on the -J command when a cookie file is configured', function () {
    $cookies = tempnam(sys_get_temp_dir(), 'ck_');
    file_put_contents($cookies, "# Netscape\n");
    Process::fake(['*' => Process::result(output: json_encode(['id' => 'ABC']))]);

    (new YtDlpAdapter(cookiesPath: $cookies))->fetchMetadata('https://www.instagram.com/reel/ABC/', null);

    Process::assertRan(function ($process) use ($cookies) {
        $cmd = $process->command;
        $i = array_search('--cookies', $cmd, true);

        return in_array('-J', $cmd, true) && $i !== false && $cmd[$i + 1] === $cookies;
    });
    @unlink($cookies);
});

it('fetchMetadata throws PostUnavailable (advance the chain) when disabled — no process run', function () {
    Process::fake();
    expect(fn () => (new YtDlpAdapter(enabled: false))->fetchMetadata('https://www.instagram.com/reel/ABC/', null))
        ->toThrow(PostUnavailable::class);
    Process::assertNothingRan();
});

it('fetchMetadata throws PostUnavailable on a non-zero exit (missing binary / auth wall)', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'HTTP Error 401', exitCode: 1)]);
    (new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reel/ABC/', null);
})->throws(PostUnavailable::class);

it('fetchMetadata throws PostUnavailable when yt-dlp returns no usable id', function () {
    Process::fake(['*' => Process::result(output: 'not json')]);
    (new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reel/ABC/', null);
})->throws(PostUnavailable::class);

it('fetchMetadata throws PostUnavailable (advance), never propagating a process throw', function () {
    Process::fake(['*' => fn () => throw new RuntimeException('timed out')]);
    (new YtDlpAdapter)->fetchMetadata('https://www.instagram.com/reel/ABC/', null);
})->throws(PostUnavailable::class);

it('does not require auth', function () {
    expect((new YtDlpAdapter)->requiresAuth())->toBeFalse();
});
