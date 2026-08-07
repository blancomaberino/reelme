<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Support\RequestLocale;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;

/**
 * Role flags (is_influencer/is_restaurant_owner/is_admin) and stripe columns are
 * deliberately NOT fillable — they are granted by the system, never mass-assigned.
 *
 * @property int $id
 * @property string $username
 * @property string|null $name
 * @property string|null $bio
 * @property Carbon|null $birthdate
 * @property list<string>|null $favorite_topics
 * @property list<string>|null $favorite_foods
 * @property string|null $avatar_path
 * @property bool $is_public
 * @property string $locale
 * @property bool $is_influencer
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $stripe_connect_onboarded_at
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_ts
 */
#[Fillable(['name', 'username', 'email', 'password', 'avatar_path', 'bio', 'birthdate', 'favorite_topics', 'favorite_foods', 'is_public', 'preferred_analysis_model'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmailContract
{
    /**
     * The language used when the stored one isn't one the app ships.
     *
     * The shipped SET is {@see RequestLocale::SUPPORTED}, deliberately not
     * redeclared here: that constant already gates what gets WRITTEN to the
     * column, and a second copy would let the write allowlist and the read
     * guard drift apart — the exact pair that must agree.
     */
    public const DEFAULT_LOCALE = 'es';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Searchable, SoftDeletes;

    // MustVerifyEmail: gives hasVerifiedEmail()/markEmailAsVerified() (T-066).
    // We send our own 6-digit code (EmailVerificationService), NOT Laravel's
    // link-based sendEmailVerificationNotification(), which is left unused.
    use MustVerifyEmail;

    /**
     * Gate the Filament admin panel to admins — enforced in EVERY environment
     * (the panel is session-authed and separate from the API's Sanctum tokens).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * The language to compose this user's notifications in (HasLocalePreference).
     *
     * Laravel honours this automatically for every queued notification, which is
     * the whole reason for the contract: the string is built in a worker long
     * after the request that triggered it, where there is no `Accept-Language`
     * and no session to read a language off. Returning it here means no
     * notification class has to remember to pass a locale.
     *
     * Guarded rather than returned raw: a column value outside the shipped set
     * (a stale row, a client that sent `pt-BR`) would resolve to no translation
     * file at all and render the raw `notifications.share.published.title` key
     * to a user. Falling back to Spanish is always readable.
     */
    public function preferredLocale(): string
    {
        return in_array($this->locale, RequestLocale::SUPPORTED, true)
            ? $this->locale
            : self::DEFAULT_LOCALE;
    }

    /**
     * People search (T-077): only PUBLIC profiles are indexed — a private
     * (`is_public = false`) user is never discoverable via search, mirroring the
     * profile 404 gate. Flipping `is_public` re-syncs the index on save.
     */
    public function shouldBeSearchable(): bool
    {
        // Cast, not raw: right after register the attribute is unset in memory
        // (the DB default hasn't been read back), which would be null — a private
        // user, a just-registered one, and an unloaded one all resolve to false
        // and get (re)indexed on their next profile-bearing save / reindex.
        return (bool) $this->is_public;
    }

    /**
     * The searchable document (T-077): match on handle + display name + bio.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'bio' => $this->bio,
        ];
    }

    /** @return HasMany<Share, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    /**
     * Follow edges this user created (T-037).
     *
     * @return HasMany<Follow, $this>
     */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_user_id');
    }

    /** @return MorphMany<Follow, $this> */
    public function followers(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followee');
    }

    /**
     * Linked platform accounts whose OAuth tokens authorize private-post fetches
     * (T-015). Cascade-deleted with the user at the DB level.
     *
     * @return HasMany<PlatformAccount, $this>
     */
    public function platformAccounts(): HasMany
    {
        return $this->hasMany(PlatformAccount::class);
    }

    /**
     * Registered Expo push targets (T-027). Cascade-deleted with the user.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Expo-channel routing: every one of the user's registered push tokens.
     * A user with no devices simply receives no push (the DB notification still
     * lands for the M3 notification center).
     *
     * @return list<string>
     */
    public function routeNotificationForExpo(): array
    {
        return $this->devices()->pluck('expo_push_token')->all();
    }

    /**
     * Verified operator claims this user holds (T-041) — the source of truth for
     * which venues they may run offers for.
     *
     * @return HasMany<PlaceClaim, $this>
     */
    public function placeClaims(): HasMany
    {
        return $this->hasMany(PlaceClaim::class);
    }

    /**
     * Does this user operate the place — i.e. hold its one VERIFIED claim
     * (T-041, 06 §2.1)? The gate on every offer write (T-042) and, downstream,
     * on verifying redemptions (T-043).
     *
     * Derived from `place_claims` on every call rather than mirrored onto a
     * column: the partial unique index already guarantees at most one verified
     * claim per place, so a cached flag would be a second copy of a fact the
     * database owns — and revoking a claim would have to remember to clear it.
     * Uses the loaded relation when there is one, so a list of offers costs no
     * N+1.
     */
    public function ownsPlace(Place $place): bool
    {
        if ($this->relationLoaded('placeClaims')) {
            // Cast both sides: `place_claims.place_id` carries no integer cast,
            // so its PHP type is the driver's choice. A strict comparison that
            // silently became int-vs-string would deny a legitimate operator
            // their own venue — and only on the paths that preload the relation,
            // which is the hardest kind of bug to reproduce.
            return $this->placeClaims->contains(
                fn (PlaceClaim $claim) => (int) $claim->place_id === (int) $place->id
                    && $claim->status === ClaimStatus::Verified,
            );
        }

        return $this->placeClaims()
            ->where('place_id', $place->id)
            ->where('status', ClaimStatus::Verified)
            ->exists();
    }

    /**
     * This user's subledger rows (T-044) — the wallet's statement.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Is 2FA actually switched on (T-068)?
     *
     * Keyed on the confirmation, never on the secret alone: a secret with no
     * confirmation means setup was started and abandoned, and enforcing on that
     * would lock out anyone who opened the setup screen and closed it again.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Distinguishes a self-requested deletion from an admin ban (T-050).
            // Both set `deleted_at`; only one of them may be undone by signing
            // back in, and this column is the only thing that says which.
            'deletion_requested_at' => 'datetime',
            'stripe_connect_onboarded_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted, not hashed (T-068): both must be readable again — the
            // secret to verify every login, the codes to show back to a user who
            // asks for them after confirming their password.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'birthdate' => 'date',
            'favorite_topics' => 'array',
            'favorite_foods' => 'array',
            'is_influencer' => 'boolean',
            'is_restaurant_owner' => 'boolean',
            'is_admin' => 'boolean',
            'is_public' => 'boolean',
        ];
    }
}
