<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Support\AgeCheck;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, EmailVerificationService $verification): JsonResponse
    {
        // Before anything is written. A refused signup must leave no account,
        // no token and no verification email behind (T-113).
        AgeCheck::enforce($request->string('date_of_birth')->toString());

        /*
         * `date_of_birth` is excluded EXPLICITLY, and that is not belt-and-braces
         * (T-113).
         *
         * It is currently dropped anyway, because the attribute is absent from
         * the model's Fillable list — but that is an accident of an unrelated
         * decision, not a guarantee. The day someone adds a birthdate field to
         * that list for a profile feature, this line would start quietly
         * persisting a date the whole gate exists to discard, and every test
         * would still pass. Naming it here makes the intent survive that change;
         * `RegisterAgeGateTest` asserts the column stays null.
         */
        $user = new User($request->safe()->except('device_name', 'date_of_birth'));

        // The OUTCOME of the check, never the date behind it — assigned before
        // the row is written, so this is ONE insert.
        //
        // It was `create()` followed by a second `save()`, which is two writes
        // with a window between them: a failure in the second leaves an account
        // that exists and has never been age-verified, and the retry path is a
        // registration that now collides on the username. A transaction would
        // close the window; not having a window is better.
        $user->age_verified_at = now();
        $user->save();

        // Reload so DB-side defaults (role flags, is_public) are reflected in the
        // response rather than appearing as null on the freshly built model.
        $user->refresh();

        // Email a confirmation code. The account is usable this first session
        // (email_verified_at is null → the app shows a "verify" banner), but the
        // user must confirm before they can log in again after logging out (T-066).
        $verification->issue($user);

        $token = $user->createToken($request->string('device_name'))->plainTextToken;

        return ApiResponse::item([
            'token' => $token,
            'user' => new UserResource($user),
        ], [], 201);
    }
}
