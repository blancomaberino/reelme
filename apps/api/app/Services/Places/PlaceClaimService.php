<?php

namespace App\Services\Places;

use App\Enums\ClaimStatus;
use App\Enums\ContactFieldSource;
use App\Enums\PlaceClaimMethod;
use App\Enums\PlaceStatus;
use App\Exceptions\ClaimException;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use App\Services\Http\PublicUrlGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Restaurant-owner verification (T-041, 06 §2.1).
 *
 * The organising rule: **every automatic method proves control of something the
 * PLACE record already lists AND that a provider — not the claimant — put there.**
 * The OTP goes to `places.phone`; the token is looked for on the host of
 * `places.website`. A claimant who could nominate the phone number or the domain
 * could verify any venue on the map, which is the whole attack.
 *
 * The listed value is only such a proof when its provenance is a provider
 * ({@see ContactFieldSource::providerVerified()}). `places.website`
 * and `places.phone` are ALSO writable from the LLM extraction, which the sharer
 * rewrites through PATCH /shares (T-117 / SEC-1) — so the automatic methods gate
 * on {@see Place::websiteIsProviderVerified()} / {@see Place::phoneIsProviderVerified()},
 * never on the bare presence of a value. A place whose contact fields are all
 * claimant-sourced has no automatic method and is routed to `document`.
 */
class PlaceClaimService
{
    private const OTP_TTL_MINUTES = 15;

    /** Wrong OTP guesses that burn the code — caps brute force per claim. */
    private const OTP_MAX_ATTEMPTS = 5;

    private const TOKEN_TTL_HOURS = 72;

    /** The path a claimant publishes the token at (06 §2.1). */
    public const WELL_KNOWN_PATH = '/.well-known/reelmap-verify.txt';

    public function __construct(private readonly PublicUrlGuard $guard) {}

    /**
     * Start a claim. Returns the pending row; `document` goes straight to the
     * admin queue, the other two carry working state to verify against.
     */
    public function start(Place $place, User $user, PlaceClaimMethod $method): PlaceClaim
    {
        $this->assertClaimable($place, $user);

        return match ($method) {
            PlaceClaimMethod::Phone => $this->startPhone($place, $user),
            PlaceClaimMethod::Website => $this->startWebsite($place, $user),
            PlaceClaimMethod::Document => $this->startDocument($place, $user),
        };
    }

    /**
     * Send a 6-digit code to the number ON THE PLACE.
     *
     * The code is stored hashed, so a database read cannot complete a claim, and
     * the response never contains it — the point is to prove the claimant can
     * receive calls at the listed number.
     */
    private function startPhone(Place $place, User $user): PlaceClaim
    {
        // Provenance, not presence: a phone the sharer typed through the
        // extraction is not a number they can be called back on to prove
        // ownership (T-117 / SEC-1). Only a provider-sourced number qualifies;
        // anything else — absent or claimant-nominated — routes to a document
        // claim, which the message points at.
        if (! $place->phoneIsProviderVerified()) {
            throw ClaimException::reason(
                'no_phone_on_file',
                'We have no verified phone number for this place. Try verifying with your website, or upload a document.',
            );
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $claim = $this->upsertPending($place, $user, PlaceClaimMethod::Phone, [
            'otp' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)->toIso8601String(),
            // The exact provider-sourced number this claim is a proof of, pinned
            // at start so verification can refuse a claim whose number has since
            // changed or lost its provenance (T-117 / SEC-1 — start→verify TOCTOU).
            'phone' => $place->phone,
            // Stored so the claim screen can say "…ending 8891" without the API
            // ever handing back the full number it is calling.
            'phone_last4' => mb_substr(preg_replace('/\D/', '', $place->phone) ?? '', -4),
        ]);

        // TODO(T-041): wire to the SMS/robocall provider. Deliberately a log line
        // rather than a stub provider — a fake "sent" would make the flow look
        // finished when nothing leaves the building.
        Log::info('place_claim.otp_issued', ['claim_id' => $claim->id, 'place_id' => $place->id]);

        return $claim;
    }

    /** Issue a token for the claimant to publish on their own domain. */
    private function startWebsite(Place $place, User $user): PlaceClaim
    {
        // Provenance, not presence: the website a claim verifies against must be
        // one a provider put on the record, never one the sharer nominated
        // through the extraction/correction path — that is the SEC-1 takeover
        // (T-117). A claimant-sourced or absent website has no website method and
        // routes to a document claim.
        if (! $place->websiteIsProviderVerified()) {
            throw ClaimException::reason(
                'no_website_on_file',
                'We have no verified website for this place. Try verifying by phone, or upload a document.',
            );
        }

        return $this->upsertPending($place, $user, PlaceClaimMethod::Website, [
            // The exact provider-sourced website this claim is a proof of, pinned
            // at start so verification can refuse a claim whose website has since
            // changed or lost its provenance (T-117 / SEC-1 — start→verify TOCTOU).
            'website' => $place->website,
            'token' => 'reelmap-verify-'.Str::lower(Str::random(24)),
            'expires_at' => now()->addHours(self::TOKEN_TTL_HOURS)->toIso8601String(),
        ]);
    }

    /** Queue a document claim for a human. Nothing is auto-verified here. */
    private function startDocument(Place $place, User $user): PlaceClaim
    {
        return $this->upsertPending($place, $user, PlaceClaimMethod::Document, []);
    }

    /**
     * Check the submitted OTP.
     *
     * A wrong guess costs an attempt; five burn the code entirely, so the TTL is
     * not the only bound on guessing a six-digit number.
     */
    public function verifyPhone(Place $place, User $user, string $code): PlaceClaim
    {
        $claim = $this->pendingClaim($place, $user, PlaceClaimMethod::Phone);
        $evidence = $claim->evidence_json ?? [];

        if ($this->expired($evidence)) {
            throw ClaimException::reason('code_expired', 'That code expired. Request a new one.');
        }

        $this->assertVerifiedContactUnchanged($place, $evidence, 'phone', $place->phoneIsProviderVerified());

        if ((int) ($evidence['attempts'] ?? 0) >= self::OTP_MAX_ATTEMPTS) {
            throw ClaimException::reason('too_many_attempts', 'Too many wrong codes. Request a new one.');
        }

        if (! Hash::check($code, (string) ($evidence['otp'] ?? ''))) {
            $evidence['attempts'] = (int) ($evidence['attempts'] ?? 0) + 1;
            $claim->update(['evidence_json' => $evidence]);

            throw ClaimException::reason('code_invalid', 'That code is not right. Check and try again.');
        }

        return $this->verify($claim);
    }

    /**
     * Fetch the claimant's own domain and look for the token.
     *
     * Goes through {@see PublicUrlGuard} and refuses redirects, exactly as the
     * enrichment scraper does: this URL comes from place data that was itself
     * extracted from a third party, so it is not trusted to point at the public
     * internet. Without that, "verify my website" is a request-forgery primitive
     * pointed at anything the API server can reach.
     */
    public function verifyWebsite(Place $place, User $user): PlaceClaim
    {
        $claim = $this->pendingClaim($place, $user, PlaceClaimMethod::Website);
        $evidence = $claim->evidence_json ?? [];

        if ($this->expired($evidence)) {
            throw ClaimException::reason('token_expired', 'That verification token expired. Request a new one.');
        }

        $this->assertVerifiedContactUnchanged($place, $evidence, 'website', $place->websiteIsProviderVerified());

        $token = (string) ($evidence['token'] ?? '');
        $url = $this->verificationUrl((string) $place->website);

        try {
            $this->guard->assertPublic(
                $url,
                allowedSchemes: ['https', 'http'],
                verifyHost: (bool) config('places.claims.verify_host', true),
            );

            // No redirects: the guard vetted THIS host, and following a 302 would
            // hand the fetch to an address it never saw.
            $response = Http::timeout((int) config('places.claims.website_timeout_seconds', 8))
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable $e) {
            // A transient fetch failure must NOT burn the claim — it stays
            // pending so the operator can retry once their host is reachable.
            Log::info('place_claim.website_fetch_failed', ['claim_id' => $claim->id, 'error' => $e->getMessage()]);

            throw ClaimException::reason(
                'site_unreachable',
                "Couldn't reach your website just now. Publish the file and try again.",
            );
        }

        if (! $response->successful() || ! str_contains($response->body(), $token)) {
            throw ClaimException::reason(
                'token_not_found',
                'We could not find the verification file on your site yet.',
            );
        }

        return $this->verify($claim);
    }

    /** The exact URL the token must be published at, on the place's own host. */
    public function verificationUrl(string $website): string
    {
        $host = parse_url($website, PHP_URL_HOST);
        $scheme = parse_url($website, PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$host.self::WELL_KNOWN_PATH;
    }

    /**
     * Settle a claim as verified, atomically.
     *
     * The partial unique index (one `verified` row per place) is the real race
     * guard: two claimants verifying at the same instant cannot both win, and
     * the loser gets a clean 409 rather than a 500 from a constraint violation.
     */
    public function verify(PlaceClaim $claim): PlaceClaim
    {
        try {
            return DB::transaction(function () use ($claim) {
                $claim->update([
                    'status' => ClaimStatus::Verified,
                    'verified_at' => now(),
                    // The working state has served its purpose; keeping a hashed
                    // OTP or a live token past verification is pure liability.
                    'evidence_json' => null,
                ]);

                $this->grantOwnerRole($claim->user_id);

                // Competing pending claims on the same place are now moot. Closed
                // explicitly so the admin queue does not keep showing work that
                // can no longer be actioned.
                PlaceClaim::query()
                    ->where('place_id', $claim->place_id)
                    ->where('id', '!=', $claim->id)
                    ->where('status', ClaimStatus::Pending)
                    ->update(['status' => ClaimStatus::Rejected, 'reason' => 'claimed_by_other']);

                return $claim->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ClaimException::conflict(
                'already_claimed',
                'Someone else verified this place first. Contact support if you believe that is wrong.',
            );
        }
    }

    /** Admin approval of a document claim (Filament). */
    public function approve(PlaceClaim $claim, User $admin): PlaceClaim
    {
        $claim->reviewed_by_user_id = $admin->id;
        $claim->save();

        return $this->verify($claim);
    }

    /** Admin rejection. Never grants the role, and records who decided. */
    public function reject(PlaceClaim $claim, User $admin, string $reason = 'insufficient_evidence'): PlaceClaim
    {
        $claim->update([
            'status' => ClaimStatus::Rejected,
            'reason' => $reason,
            'reviewed_by_user_id' => $admin->id,
            'evidence_json' => null,
        ]);

        return $claim;
    }

    /**
     * `is_restaurant_owner` is a capability flag, not the ownership record — the
     * verified claim row is what scopes them to a place. Set here so the flag can
     * never be true for someone with no verified claim.
     */
    private function grantOwnerRole(int $userId): void
    {
        User::whereKey($userId)->update(['is_restaurant_owner' => true]);
    }

    /**
     * Re-assert at completion the invariant that start established: the claim
     * proves control of the SPECIFIC provider-sourced contact value the place
     * listed when the claim began. A claim started against a Google-sourced
     * website/phone is no longer a proof of ownership if that field has since
     * changed — to a value the claimant could nominate — or lost its provider
     * provenance. So verify re-checks BOTH the value and the provenance rather
     * than trusting the start-time gate; the start→verify gap is two requests
     * wide, and the extraction/correction path can move a field inside it
     * (T-117 / SEC-1). A legacy pending claim with no pinned value cannot be
     * revalidated, so it is refused — the claimant restarts, re-pinning today's
     * value.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function assertVerifiedContactUnchanged(Place $place, array $evidence, string $field, bool $providerVerified): void
    {
        $pinned = $evidence[$field] ?? null;

        if (! $providerVerified || ! is_string($pinned) || $pinned !== $place->{$field}) {
            throw ClaimException::reason(
                'contact_changed',
                'The verified contact details for this place changed. Start a new claim.',
            );
        }
    }

    private function assertClaimable(Place $place, User $user): void
    {
        // Only a reviewed, live pin is claimable. A pending pin nobody has looked
        // at yet (and any hidden/merged/removed one) must not be claimable at all
        // — half the SEC-1 exploit's speed was claiming a pin the instant it
        // published (T-117). Checked before the verified-claim lookup so it never
        // becomes an oracle for who, if anyone, already holds the place.
        if ($place->status !== PlaceStatus::Active) {
            throw ClaimException::reason(
                'place_not_claimable',
                'This place is not available to claim yet.',
            );
        }

        $verified = PlaceClaim::query()
            ->where('place_id', $place->id)
            ->where('status', ClaimStatus::Verified)
            ->first();

        if ($verified === null) {
            return;
        }

        // Idempotent for the owner, a clean conflict for anyone else — and it
        // does not reveal WHO holds it.
        throw $verified->user_id === $user->id
            ? ClaimException::conflict('already_yours', 'You have already verified this place.')
            : ClaimException::conflict('already_claimed', 'This place has already been claimed.');
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function upsertPending(Place $place, User $user, PlaceClaimMethod $method, array $evidence): PlaceClaim
    {
        $key = ['place_id' => $place->id, 'user_id' => $user->id];
        $values = [
            'method' => $method,
            'status' => ClaimStatus::Pending,
            'evidence_json' => $evidence === [] ? null : $evidence,
            'reason' => null,
            'verified_at' => null,
            'reviewed_by_user_id' => null,
        ];

        // One in-flight claim per (place, user): restarting replaces it, so a
        // re-request cannot leave two live OTPs for one person.
        $existing = PlaceClaim::query()->where($key)->where('status', ClaimStatus::Pending)->first();

        if ($existing !== null) {
            $existing->update($values);

            return $existing->refresh();
        }

        return PlaceClaim::create($key + $values);
    }

    private function pendingClaim(Place $place, User $user, PlaceClaimMethod $method): PlaceClaim
    {
        $claim = PlaceClaim::query()
            ->where('place_id', $place->id)
            ->where('user_id', $user->id)
            ->where('method', $method)
            ->where('status', ClaimStatus::Pending)
            ->first();

        if ($claim === null) {
            throw ClaimException::reason('no_pending_claim', 'Start a claim before verifying it.');
        }

        return $claim;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function expired(array $evidence): bool
    {
        $expiresAt = $evidence['expires_at'] ?? null;

        return ! is_string($expiresAt) || now()->gt($expiresAt);
    }
}
