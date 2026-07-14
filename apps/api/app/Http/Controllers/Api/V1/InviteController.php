<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteRequest;
use App\Mail\FriendInvite;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invite friends to Reelmap by email (T-069). Authed + rate-limited. An address
 * that already belongs to a user is silently skipped (the inviter is never told
 * which of their friends are already members — no enumeration), as is one this
 * inviter already emailed within the cooldown (so a friend can't be spammed).
 * The response is uniform regardless of how many were actually sent.
 */
class InviteController extends Controller
{
    /** Don't re-email the same address from the same inviter within this window. */
    private const DEDUPE_HOURS = 24;

    public function store(InviteRequest $request): JsonResponse
    {
        $user = $request->user();
        $inviterName = $user->name ?? $user->username;
        $inviteUrl = (string) config('app.url');

        /** @var list<string> $emails */
        $emails = array_values(array_unique($request->validated('emails')));

        foreach ($emails as $email) {
            // Already a member → skip silently (no "is X on Reelmap?" oracle).
            if (User::where('email', $email)->exists()) {
                continue;
            }

            // Already invited by this user recently → skip (anti-spam).
            $recentlyInvited = Invitation::query()
                ->where('inviter_user_id', $user->id)
                ->where('email', $email)
                ->where('created_at', '>', now()->subHours(self::DEDUPE_HOURS))
                ->exists();
            if ($recentlyInvited) {
                continue;
            }

            Invitation::create(['inviter_user_id' => $user->id, 'email' => $email, 'created_at' => now()]);

            try {
                Mail::to($email)->send(new FriendInvite($inviterName, $inviteUrl));
            } catch (\Throwable) {
                Log::warning('invite.send_failed', ['inviter_user_id' => $user->id]);
            }
        }

        return response()->json(['data' => ['status' => 'queued'], 'meta' => (object) []], 202);
    }
}
