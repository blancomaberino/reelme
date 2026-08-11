<?php

use App\Support\Countries;

it('bundles the officially assigned ISO 3166-1 alpha-2 codes, uniquely and in canonical form', function () {
    $codes = Countries::CODES;

    expect($codes)->toHaveCount(249)
        ->and(array_unique($codes))->toHaveCount(249);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[A-Z]{2}$/');
    }

    // Spot-check both ends and the ones the product actually cares about.
    expect($codes)->toContain('UY', 'ES', 'US', 'PT', 'AD', 'ZW');
});

/**
 * A typo in a 249-entry hand-written table is invisible: the wrong code passes
 * validation (it IS in the list) and ICU renders it as itself, so the picker
 * shows a row reading "XQ" and nobody notices. ICU knowing every code is the
 * only cheap proof the table is right.
 */
it('contains no typo — ICU recognizes every bundled code', function () {
    $unknown = array_values(array_filter(
        Countries::CODES,
        fn (string $code): bool => Countries::name($code, 'en') === $code,
    ));

    expect($unknown)->toBe([]);
});

it('is kept in ascending order, so a code is added where it belongs', function () {
    $sorted = Countries::CODES;
    sort($sorted);

    expect(Countries::CODES)->toBe($sorted);
});

it('excludes the user-assigned and reserved ranges that are not countries', function () {
    // `ZZ` is ICU's "unknown region" sentinel and would render as "Región
    // desconocida"; `UK` is reserved (the country is `GB`); `AA`/`QM`/`XA` are
    // user-assigned. Storing any of them would look like a country and behave
    // like a bug.
    foreach (['ZZ', 'UK', 'AA', 'QM', 'XA', 'EU'] as $notACountry) {
        expect(Countries::isValid($notACountry))->toBeFalse();
    }
});

it('normalizes case and blanks, without claiming anything about validity', function () {
    expect(Countries::normalize('uy'))->toBe('UY')
        ->and(Countries::normalize(' Es '))->toBe('ES')
        ->and(Countries::normalize(''))->toBeNull()
        ->and(Countries::normalize(null))->toBeNull()
        // Normalization is a shape change, not a check — `zz` uppercases fine
        // and is still rejected by isValid().
        ->and(Countries::normalize('zz'))->toBe('ZZ')
        ->and(Countries::isValid(Countries::normalize('zz')))->toBeFalse();
});

it('localizes a country name through ICU', function () {
    expect(Countries::name('ES', 'en'))->toBe('Spain')
        ->and(Countries::name('ES', 'es'))->toBe('España')
        ->and(Countries::name('UY', 'es'))->toBe('Uruguay')
        ->and(Countries::name('US', 'es'))->toBe('Estados Unidos');
});

/**
 * The trap this whole class exists for: `Locale::getDisplayRegion()` never
 * fails. It echoes what it does not know, so a bogus code renders as a
 * plausible-looking name and validation built on it would accept anything.
 */
it('returns null for a code ICU would happily echo back', function () {
    expect(Countries::name('QQ', 'en'))->toBeNull()
        ->and(Countries::name('U1', 'en'))->toBeNull()
        ->and(Countries::name('ZZ', 'es'))->toBeNull()
        ->and(Countries::name(null, 'en'))->toBeNull();

    // Proof the guard is load-bearing rather than defensive decoration: ICU
    // itself answers with the input.
    expect(Locale::getDisplayRegion('-QQ', 'en'))->toBe('QQ');
});

it('sorts the catalog by localized name, not by byte order', function () {
    $es = Countries::catalog('es');

    expect($es)->toHaveCount(249)
        ->and($es[0])->toHaveKeys(['code', 'name']);

    $names = array_column($es, 'name');

    // Asserted against ICU's own collation rather than against a named country:
    // the exact spelling of an accented name moves between ICU releases (the
    // container ships 74, a laptop may ship 56, and they disagree on "Islas
    // Aland"), so a test pinned to one of them fails on a base-image bump for
    // no reason.
    $collator = new Collator('es');
    $collated = $names;
    $collator->sort($collated);
    expect($names)->toBe($collated);

    // And that this is not merely byte order, which strands every accented name
    // after "Zimbabue" — over 249 names the two orders cannot coincide.
    $byteOrder = $names;
    sort($byteOrder, SORT_STRING);
    expect($names)->not->toBe($byteOrder);
});

it('gives a different catalog per locale', function () {
    $spainEn = collect(Countries::catalog('en'))->firstWhere('code', 'ES');
    $spainEs = collect(Countries::catalog('es'))->firstWhere('code', 'ES');

    expect($spainEn['name'])->toBe('Spain')
        ->and($spainEs['name'])->toBe('España');
});
