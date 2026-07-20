<?php

use App\Enums\Platform;

it('maps every platform to a human-facing label', function () {
    expect(Platform::Instagram->label())->toBe('Instagram')
        ->and(Platform::X->label())->toBe('X')
        ->and(Platform::Tiktok->label())->toBe('TikTok')
        ->and(Platform::Youtube->label())->toBe('YouTube');
});
