<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expo push-token registration (T-027, 03 §2 / 05 §5.1). Tokens are per-install,
 * not per-user: {@see store} upserts on the token and reassigns it to the
 * currently authenticated user so a shared device never delivers one user's
 * pushes to another. Logout calls {@see destroy} with the raw token.
 */
class DeviceController extends Controller
{
    /**
     * Register (or re-register) this install's Expo push token for the authed
     * user. Upsert on the unique `expo_push_token`: an existing row is moved to
     * the current user and its metadata + `last_seen_at` refreshed.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:24'],
        ]);

        $device = Device::updateOrCreate(
            ['expo_push_token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $device->id,
                'platform' => $device->platform,
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Unregister a device. Accepts either the numeric device id (owner-only) or
     * the raw Expo token (logout convenience — the client rarely knows the id).
     * Idempotent: a missing/foreign token is a no-op 204 rather than a 404 so a
     * logout never fails on a token this user doesn't own.
     */
    public function destroy(Request $request, string $device): JsonResponse
    {
        $user = $request->user();

        if (ctype_digit($device)) {
            $row = Device::query()->whereKey((int) $device)->first();

            // Owner-only: a real 404 leaks nothing, and only the owner may delete.
            if ($row === null || $row->user_id !== $user->id) {
                abort(404);
            }

            $row->delete();
        } else {
            // Delete-by-token: scoped to this user so it can't prune someone
            // else's install even if they somehow share a token string.
            Device::query()
                ->where('expo_push_token', $device)
                ->where('user_id', $user->id)
                ->delete();
        }

        return response()->json(null, 204);
    }
}
