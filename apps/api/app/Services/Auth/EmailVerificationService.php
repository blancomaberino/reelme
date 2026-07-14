<?php

namespace App\Services\Auth;

use App\Mail\VerifyEmailCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issues + verifies the 6-digit email confirmation codes (T-066). Codes are
 * stored hashed (never in the clear), expire after 15 minutes, and resends are
 * throttled so the endpoint can't be used to spam a mailbox.
 */
class EmailVerificationService
{
    private const EXPIRES_MINUTES = 15;

    private const RESEND_THROTTLE_SECONDS = 60;

    /** Wrong guesses that burn a code — caps brute-force independent of IP. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Generate a fresh code and email it. Returns false (and sends nothing) when
     * a code was already issued within the resend window, so callers can surface
     * a "please wait" without leaking timing.
     */
    public function issue(User $user): bool
    {
        $existing = DB::table('email_verification_codes')->where('email', $user->email)->first();
        if ($existing !== null
            && Carbon::parse($existing->created_at)->gt(now()->subSeconds(self::RESEND_THROTTLE_SECONDS))) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('email_verification_codes')->updateOrInsert(
            ['email' => $user->email],
            ['code' => Hash::make($code), 'attempts' => 0, 'created_at' => now()],
        );

        // Never let a mail/queue outage fail registration — the code row is
        // persisted, so the user can always resend. Log and move on.
        try {
            Mail::to($user->email)->send(new VerifyEmailCode($user, $code));
        } catch (\Throwable $e) {
            Log::warning('email_verification.send_failed', ['user_id' => $user->id]);
        }

        return true;
    }

    /**
     * Check a submitted code against the stored hash. On success the code is
     * consumed (single-use). Does not mark the user — the caller does that so it
     * can also issue a session token atomically.
     */
    public function check(string $email, string $code): bool
    {
        $row = DB::table('email_verification_codes')->where('email', $email)->first();
        if ($row === null) {
            // Constant-time-ish: still run a hash so a missing row can't be told
            // apart from a present one by response latency.
            Hash::check($code, '$2y$12$usesomesillystringforsalttttttttttttttttttttttttttttttte');

            return false;
        }

        // Expired or too many wrong guesses → burn the code (a fresh one must be
        // requested, which is 60s-throttled per email — this bounds brute force
        // regardless of how many IPs the attacker spreads across).
        $expired = Carbon::parse($row->created_at)->lt(now()->subMinutes(self::EXPIRES_MINUTES));
        if ($expired || $row->attempts >= self::MAX_ATTEMPTS) {
            DB::table('email_verification_codes')->where('email', $email)->delete();

            return false;
        }

        if (! Hash::check($code, $row->code)) {
            DB::table('email_verification_codes')->where('email', $email)->increment('attempts');

            return false;
        }

        DB::table('email_verification_codes')->where('email', $email)->delete();

        return true;
    }
}
