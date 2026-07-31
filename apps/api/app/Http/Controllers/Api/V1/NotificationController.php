<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Support\KeysetPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The in-app notification center (T-040, 03 §2.15).
 *
 * T-027 shipped Expo push, but a push is ephemeral: dismissed, missed while
 * offline, or arriving on a device the user has since replaced, it is gone.
 * Every notification class now writes a database twin with the same payload, so
 * the center is the durable record and the push is the interruption.
 *
 * Scoping is by construction, not by check: every query starts from
 * `$user->notifications()`, so there is no code path that could read or mutate
 * another account's rows.
 */
class NotificationController extends Controller
{
    /** Page size bounds — the app renders an infinite list, not pages. */
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'unread' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);

        $user = $this->user($request);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $unreadOnly = in_array($validated['unread'] ?? null, ['1', 1, true], true);

        $query = $user->notifications()->getQuery()->orderByDesc('created_at')->orderByDesc('id');
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        // Laravel's own cursor paginator: `notifications.id` is a UUID, so the
        // KeysetCursor row-value comparisons the place/tag endpoints use do not
        // apply — ordering is (created_at, id) and the paginator encodes both.
        $paginator = $query->cursorPaginate($limit, ['*'], 'cursor', $validated['cursor'] ?? null);

        $page = KeysetPage::of(
            $paginator->items(),
            $paginator->perPage(),
            $paginator->nextCursor()?->encode(),
            $paginator->previousCursor()?->encode(),
        );

        return ApiResponse::page(
            NotificationResource::collection($page->items),
            $page,
            // On EVERY page, not just the first: the app badges from whatever
            // response it last saw, and a stale count on page two would show a
            // number the user already cleared.
            ['unread_count' => $this->unreadCount($user)],
        );
    }

    /**
     * Mark notifications read — `{ids: [...]}` or `{all: true}` (03 §2.15).
     *
     * Ids belonging to another user are silently ignored rather than 403'd:
     * a 403 would confirm that an id EXISTS, turning this into an oracle for
     * enumerating other people's notification ids.
     */
    public function read(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'ids' => ['sometimes', 'array', 'max:'.self::MAX_LIMIT],
            'ids.*' => ['string', 'uuid'],
        ]);

        $all = (bool) ($validated['all'] ?? false);
        $ids = $validated['ids'] ?? [];

        // Neither given is a malformed request, not an empty no-op: silently
        // succeeding would let a client think it cleared a badge it never did.
        if (! $all && $ids === []) {
            throw ValidationException::withMessages([
                'ids' => 'Provide notification ids, or all: true.',
            ]);
        }

        $user = $this->user($request);
        $query = $user->unreadNotifications()->getQuery();
        if (! $all) {
            $query->whereIn('id', $ids);
        }
        $query->update(['read_at' => now()]);

        return ApiResponse::item(['unread_count' => $this->unreadCount($user)]);
    }

    private function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
