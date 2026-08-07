<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\Gdpr\ExportUserData;
use App\Models\User;
use App\Services\Gdpr\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two rights a user can exercise on their own data (T-050, NFR-10):
 * take a copy, and be forgotten.
 *
 * Both act on the caller and nobody else — there is no user id in either
 * signature, which is what makes them safe to expose to any authenticated
 * session without a policy.
 */
class GdprController extends Controller
{
    /**
     * POST /me/export — 202, "we're working on it".
     *
     * Deliberately not synchronous: the archive spans a dozen tables and gets
     * zipped and uploaded, so the users with the most data are exactly the ones
     * a synchronous version would time out for.
     */
    public function export(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        ExportUserData::dispatch($user->id);

        return ApiResponse::item([
            'status' => 'queued',
            'delivery' => 'email',
        ], [], 202);
    }

    /**
     * DELETE /me — end the account now, erase it later.
     *
     * The response carries `purge_at` because "deleted" here means two different
     * things at two different times, and the client has to be able to say which:
     * the session is over immediately, the data goes at that timestamp, and
     * until then signing back in undoes the whole thing.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deletion->request($user);

        return ApiResponse::item([
            'status' => 'scheduled',
            'purge_at' => $deletion->purgeAt($user)->toIso8601String(),
            'grace_days' => (int) config('gdpr.purge_grace_days'),
        ]);
    }
}
