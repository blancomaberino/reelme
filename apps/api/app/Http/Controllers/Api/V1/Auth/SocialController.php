<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SocialController extends Controller
{
    /**
     * Sign in with Apple/Google (03-api-design §2.1).
     *
     * TODO: implement once Apple/Google client credentials exist. Verify the
     * provider `id_token`, then create-or-link the account and issue a token.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_implemented',
                'message' => 'Social sign-in is not available yet.',
                'details' => (object) [],
                'request_id' => 'req_'.Str::ulid(),
            ],
        ], 501);
    }
}
