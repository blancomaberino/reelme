<?php

use App\Support\CsvList;

/**
 * The parse behind `?include=a,b` and `?types=a,b` (T-128).
 *
 * The interesting cases are all about what "no member" means, because the
 * caller acts on the difference: null is "the parameter was not sent", `[]` is
 * "separators and nothing between them", and a member that merely LOOKS empty
 * is a member.
 */
it('keeps "0" — a falsey member is still a member', function () {
    // `array_filter` with no callback drops "0", so `?include=0` was filtered
    // to `[]` and read as "embed nothing", silently passing the unknown-include
    // check instead of failing it (found by review, T-128).
    expect(CsvList::parse('0'))->toBe(['0'])
        ->and(CsvList::parse('sources,0'))->toBe(['sources', '0'])
        ->and(CsvList::parse('0,false'))->toBe(['0', 'false']);
});

it('trims, dedupes and preserves first-seen order', function () {
    expect(CsvList::parse(' sources , offers ,sources'))->toBe(['sources', 'offers']);
});

it('answers NULL for absent, blank, or non-string — not []', function () {
    // The distinction each caller reads differently: an absent `include` embeds
    // nothing, an absent `types` means every type.
    expect(CsvList::parse(null))->toBeNull()
        ->and(CsvList::parse(''))->toBeNull()
        ->and(CsvList::parse('   '))->toBeNull()
        // `?include[]=x` — narrowed here rather than cast, which is what turned
        // a 500 back into the 422 it should always have been.
        ->and(CsvList::parse(['sources']))->toBeNull()
        ->and(CsvList::parse(42))->toBeNull();
});

it('answers [] for separators with no members — an explicit empty set', function () {
    expect(CsvList::parse(','))->toBe([])
        ->and(CsvList::parse(' , , '))->toBe([]);
});
