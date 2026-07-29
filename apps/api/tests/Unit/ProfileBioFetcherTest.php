<?php

use App\Enums\Platform;
use App\Services\Influencers\ProfileBioFetcher;
use App\Services\Media\Instagram\InstagramWebClient;

/** A fetcher wrapping an InstagramWebClient stubbed to return $profile. */
function fetcherReturning(?array $profile): ProfileBioFetcher
{
    $client = Mockery::mock(InstagramWebClient::class);
    $client->shouldReceive('profile')->andReturn($profile);

    return new ProfileBioFetcher($client);
}

it('returns the Instagram biography for a readable profile', function () {
    expect(fetcherReturning(['biography' => 'Best tacos in town 🌮'])->fetchProfileBio(Platform::Instagram, 'chef'))
        ->toBe('Best tacos in town 🌮');
});

it('returns null when the Instagram profile cannot be read', function () {
    // Transport failure / private / dead account → web client yields null.
    expect(fetcherReturning(null)->fetchProfileBio(Platform::Instagram, 'ghost'))->toBeNull();
});

it('treats a blank or missing biography as unreadable', function () {
    expect(fetcherReturning(['biography' => '   '])->fetchProfileBio(Platform::Instagram, 'x'))->toBeNull();
    expect(fetcherReturning(['full_name' => 'No Bio'])->fetchProfileBio(Platform::Instagram, 'x'))->toBeNull();
});

it('returns null for platforms with no profile transport without hitting the client', function () {
    $client = Mockery::mock(InstagramWebClient::class);
    $client->shouldNotReceive('profile');
    $fetcher = new ProfileBioFetcher($client);

    expect($fetcher->fetchProfileBio(Platform::Tiktok, 'x'))->toBeNull()
        ->and($fetcher->fetchProfileBio(Platform::X, 'x'))->toBeNull()
        ->and($fetcher->fetchProfileBio(Platform::Youtube, 'x'))->toBeNull();
});
