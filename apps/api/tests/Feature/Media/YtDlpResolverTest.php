<?php

use App\Models\SourcePost;
use App\Services\Media\Images\YtDlpResolver;
use Illuminate\Support\Facades\Process;

/** A non-persisted post carrying just the URL the resolver reads. */
function ytPost(string $url = 'https://www.instagram.com/p/ABC123/'): SourcePost
{
    $post = new SourcePost;
    $post->url = $url;
    $post->id = 1;

    return $post;
}

function fakeYtDlp(array $json): void
{
    Process::fake(['*' => Process::result(output: json_encode($json))]);
}

it('returns one full-res image per carousel slide, in order', function () {
    fakeYtDlp([
        'entries' => [
            ['display_url' => 'https://cdn.example.com/1.jpg'],
            ['display_url' => 'https://cdn.example.com/2.jpg'],
            ['display_url' => 'https://cdn.example.com/3.jpg'],
        ],
    ]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe([
        'https://cdn.example.com/1.jpg',
        'https://cdn.example.com/2.jpg',
        'https://cdn.example.com/3.jpg',
    ]);
});

it('resolves a single image post', function () {
    fakeYtDlp(['display_url' => 'https://cdn.example.com/solo.jpg']);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe(['https://cdn.example.com/solo.jpg']);
});

it('uses the image url of an image-only entry (no display_url)', function () {
    fakeYtDlp(['entries' => [['ext' => 'jpg', 'url' => 'https://cdn.example.com/a.jpg']]]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe(['https://cdn.example.com/a.jpg']);
});

it('falls back to the largest thumbnail for a video slide', function () {
    fakeYtDlp(['entries' => [[
        'ext' => 'mp4',
        'vcodec' => 'h264',
        'url' => 'https://cdn.example.com/video.mp4',
        'thumbnails' => [
            ['url' => 'https://cdn.example.com/small.jpg', 'width' => 150, 'height' => 150],
            ['url' => 'https://cdn.example.com/big.jpg', 'width' => 1080, 'height' => 1080],
        ],
    ]]]);

    // The video URL is NOT returned; the largest cover frame is.
    expect((new YtDlpResolver)->resolve(ytPost()))->toBe(['https://cdn.example.com/big.jpg']);
});

it('drops non-https urls', function () {
    fakeYtDlp(['entries' => [
        ['display_url' => 'http://cdn.example.com/insecure.jpg'],
        ['display_url' => 'https://cdn.example.com/ok.jpg'],
    ]]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe(['https://cdn.example.com/ok.jpg']);
});

it('dedupes repeated image urls', function () {
    fakeYtDlp(['entries' => [
        ['display_url' => 'https://cdn.example.com/same.jpg'],
        ['display_url' => 'https://cdn.example.com/same.jpg'],
    ]]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe(['https://cdn.example.com/same.jpg']);
});

it('returns nothing when yt-dlp exits non-zero (missing binary / auth wall)', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ERROR: login required', exitCode: 1)]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe([]);
});

it('returns nothing on unparseable output', function () {
    Process::fake(['*' => Process::result(output: 'not json')]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe([]);
});

it('does not run yt-dlp when disabled', function () {
    Process::fake();

    expect((new YtDlpResolver(enabled: false))->resolve(ytPost()))->toBe([]);
    Process::assertNothingRan();
});

it('does not run yt-dlp when the post has no url', function () {
    Process::fake();
    $post = ytPost('');

    expect((new YtDlpResolver)->resolve($post))->toBe([]);
    Process::assertNothingRan();
});

it('does not run yt-dlp for a non-http url (manual:// caption share)', function () {
    Process::fake();

    expect((new YtDlpResolver)->resolve(ytPost('manual://abc123')))->toBe([]);
    Process::assertNothingRan();
});

it('never throws when yt-dlp times out / the process errors', function () {
    // A hang throws ProcessTimedOutException; resolve() must swallow it so the
    // ingest chain falls through instead of failing prepare_media.
    Process::fake(['*' => fn () => throw new RuntimeException('timed out')]);

    expect((new YtDlpResolver)->resolve(ytPost()))->toBe([]);
});

it('passes the url last, after a -- end-of-options separator', function () {
    fakeYtDlp(['display_url' => 'https://cdn.example.com/x.jpg']);
    $url = 'https://www.instagram.com/p/ABC123/';

    (new YtDlpResolver)->resolve(ytPost($url));

    Process::assertRan(function ($process) use ($url) {
        $cmd = (array) $process->command;

        return end($cmd) === $url && $cmd[count($cmd) - 2] === '--';
    });
});

it('passes --cookies when a readable cookie file is configured', function () {
    fakeYtDlp(['display_url' => 'https://cdn.example.com/x.jpg']);
    $cookies = tempnam(sys_get_temp_dir(), 'ck_');

    (new YtDlpResolver(cookiesPath: $cookies))->resolve(ytPost());

    Process::assertRan(fn ($process) => in_array('--cookies', (array) $process->command, true)
        && in_array($cookies, (array) $process->command, true));
    @unlink($cookies);
});

it('omits --cookies when the configured file is missing', function () {
    fakeYtDlp(['display_url' => 'https://cdn.example.com/x.jpg']);

    (new YtDlpResolver(cookiesPath: '/no/such/cookies.txt'))->resolve(ytPost());

    Process::assertRan(fn ($process) => ! in_array('--cookies', (array) $process->command, true));
});
