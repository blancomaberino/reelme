<?php

use App\Adapters\Data\LinkedAccount;
use App\Enums\Platform;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores tokens encrypted at rest but exposes them via the accessor', function () {
    $account = PlatformAccount::factory()->create(['access_token' => 'secret-token']);

    // Ciphertext at rest — the raw column is never the plaintext token.
    $raw = DB::table('platform_accounts')->where('id', $account->id)->value('access_token');
    expect($raw)->not->toBe('secret-token')
        ->and($raw)->toBeString()
        ->and(strlen((string) $raw))->toBeGreaterThan(strlen('secret-token'));

    // The model transparently decrypts it back.
    expect($account->fresh()->access_token)->toBe('secret-token');
});

it('never serializes token fields', function () {
    $account = PlatformAccount::factory()->create();

    $array = $account->toArray();
    expect($array)->not->toHaveKey('access_token')
        ->and($array)->not->toHaveKey('refresh_token');
});

it('reports active vs expired status from the token expiry', function () {
    $active = PlatformAccount::factory()->create(['token_expires_at' => now()->addDay()]);
    $expired = PlatformAccount::factory()->expired()->create();
    $noExpiry = PlatformAccount::factory()->create(['token_expires_at' => null]);

    expect($active->isExpired())->toBeFalse()
        ->and($active->status())->toBe('active')
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->status())->toBe('expired')
        // A null expiry is treated as active (never-expiring / re-verified on use).
        ->and($noExpiry->isExpired())->toBeFalse();
});

it('maps a usable account to a LinkedAccount DTO, else null', function () {
    $active = PlatformAccount::factory()->create([
        'handle' => 'lagranburgerok',
        'access_token' => 'tok_live',
    ]);

    $dto = $active->toLinkedAccount();
    expect($dto)->toBeInstanceOf(LinkedAccount::class)
        ->and($dto->platform)->toBe(Platform::Instagram)
        ->and($dto->handle)->toBe('lagranburgerok')
        ->and($dto->accessToken)->toBe('tok_live');

    // Expired or token-less accounts are treated as "no token".
    expect(PlatformAccount::factory()->expired()->create()->toLinkedAccount())->toBeNull()
        ->and(PlatformAccount::factory()->create(['access_token' => null])->toLinkedAccount())->toBeNull();
});

it('cascades away when its user is hard-deleted', function () {
    $user = User::factory()->create();
    $account = PlatformAccount::factory()->for($user)->create();

    $user->forceDelete();

    expect(PlatformAccount::find($account->id))->toBeNull();
});
