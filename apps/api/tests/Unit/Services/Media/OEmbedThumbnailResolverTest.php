<?php

use App\Models\SourcePost;
use App\Services\Media\Images\OEmbedThumbnailResolver;

it('returns the https thumbnail url from oembed_json', function () {
    $post = new SourcePost;
    $post->oembed_json = ['thumbnail_url' => 'https://cdn.example.com/hero.jpg'];

    expect((new OEmbedThumbnailResolver)->resolve($post))
        ->toBe(['https://cdn.example.com/hero.jpg']);
});

it('returns nothing when there is no thumbnail', function () {
    $post = new SourcePost;
    $post->oembed_json = ['author_name' => 'someone'];

    expect((new OEmbedThumbnailResolver)->resolve($post))->toBe([]);
});

it('rejects a non-https thumbnail url (SSRF/downgrade guard)', function () {
    $post = new SourcePost;
    $post->oembed_json = ['thumbnail_url' => 'http://cdn.example.com/hero.jpg'];

    expect((new OEmbedThumbnailResolver)->resolve($post))->toBe([]);
});

it('returns nothing when oembed_json is null', function () {
    $post = new SourcePost;

    expect((new OEmbedThumbnailResolver)->resolve($post))->toBe([]);
});
