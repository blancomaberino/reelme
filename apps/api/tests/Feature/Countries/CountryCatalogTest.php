<?php

use App\Support\Contracts\ApiSchema;

it('serves the whole catalog with localized names', function () {
    $res = $this->getJson('/api/v1/countries?locale=en')->assertOk();

    $rows = $res->json('data');
    expect($rows)->toHaveCount(249)
        ->and($res->json('meta.locale'))->toBe('en');

    $spain = collect($rows)->firstWhere('code', 'ES');
    expect($spain['name'])->toBe('Spain');

    // Every row matches the contract, not just the one we looked at.
    foreach ($rows as $row) {
        expect(ApiSchema::errors(ApiSchema::validate($row, 'country')))->toBe([]);
    }
});

it('follows Accept-Language, so the picker is localized without a client dataset', function () {
    $es = $this->getJson('/api/v1/countries', ['Accept-Language' => 'es-419,es;q=0.9'])->assertOk();
    expect(collect($es->json('data'))->firstWhere('code', 'ES')['name'])->toBe('España')
        ->and($es->json('meta.locale'))->toBe('es');

    $en = $this->getJson('/api/v1/countries', ['Accept-Language' => 'en-GB'])->assertOk();
    expect(collect($en->json('data'))->firstWhere('code', 'ES')['name'])->toBe('Spain')
        ->and($en->json('meta.locale'))->toBe('en');
});

it('is ordered by the localized name, so the list reads alphabetically in either language', function () {
    $names = collect($this->getJson('/api/v1/countries?locale=en')->json('data'))->pluck('name');

    // Not `sort()`: byte order and collation disagree on accented names, and it
    // is the collated order the endpoint promises.
    $collator = new Collator('en');
    $sorted = $names->all();
    $collator->sort($sorted);

    expect($names->all())->toBe($sorted);
});

it('is public — the picker has to work before you finish signing up', function () {
    $this->getJson('/api/v1/countries')->assertOk();
});
