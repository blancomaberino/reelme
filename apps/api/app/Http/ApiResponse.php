<?php

namespace App\Http;

use App\Support\KeysetPage;
use Illuminate\Http\JsonResponse;

/**
 * The `{data, meta}` envelope every v1 endpoint answers in (03 §1) — T-105.
 *
 * It was previously spelled out inline at ~35 call sites, including twenty
 * copies of `'meta' => (object) []`. That is not just repetition: the cast is
 * load-bearing (an empty PHP array encodes as `[]`, and the contract says
 * `{}`), so every hand-written envelope was one forgotten cast away from
 * shipping the wrong JSON type to clients. Here it cannot be forgotten.
 *
 * The contract conformance tests (T-102) pin the shape; this makes the shape
 * enforceable from one place.
 */
final class ApiResponse
{
    /**
     * A single resource, an object, or any already-shaped payload.
     *
     * `$meta` is cast to an object so an empty one encodes as `{}` rather than
     * `[]`. A non-empty associative array encodes identically either way, so
     * the cast is safe to apply unconditionally.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function item(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => (object) $meta], $status);
    }

    /**
     * A collection with no pagination — a full facet list, a small fixed set.
     * Distinct from {@see page()} only in intent, which is the point: a reader
     * can tell "this endpoint does not paginate" from "this one forgot to".
     *
     * @param  array<string, mixed>  $meta
     */
    public static function collection(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return self::item($data, $meta, $status);
    }

    /**
     * A keyset-paginated page.
     *
     * `$data` is the rendered payload (a Resource collection, a mapped array);
     * `$page` carries the cursors. Endpoint-specific meta comes FIRST so the
     * wire order stays `{scope|viewer|…, pagination}` exactly as before.
     *
     * @param  KeysetPage<mixed>  $page
     * @param  array<string, mixed>  $meta
     */
    public static function page(mixed $data, KeysetPage $page, array $meta = [], int $status = 200): JsonResponse
    {
        return self::item($data, array_merge($meta, $page->meta()), $status);
    }

    /**
     * A successful write with nothing to return: `{"data": null, "meta": {}}`
     * with a 200. Deliberately NOT a bodyless 204 — the mobile client parses
     * the envelope on every response, and 03 §1 makes that uniform.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function noContent(array $meta = []): JsonResponse
    {
        return self::item(null, $meta);
    }
}
