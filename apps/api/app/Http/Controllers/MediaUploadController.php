<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Handles PUT uploads to a signed local-dev media URL (see MediaUrlService).
 * The `signed` middleware validates the URL; the route is only registered
 * outside production. Writes are restricted to the two configured local media
 * disks so a signed URL can never target the web-served `public`/`local` disks.
 * Production uses native presigned R2 uploads and never this route.
 */
class MediaUploadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $disk = (string) $request->query('disk');
        $path = (string) $request->query('path');

        $allowed = [config('media.disk'), config('media.originals_disk')];

        abort_unless(
            in_array($disk, $allowed, true) && config("filesystems.disks.{$disk}.driver") === 'local',
            404,
        );

        // Belt-and-suspenders on top of Flysystem's own traversal guard.
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 422);

        // Bound the in-memory body (dev helper; large videos still fit a sane cap).
        $max = (int) config('media.max_upload_bytes');
        abort_if($max > 0 && (int) $request->header('Content-Length') > $max, 413);

        Storage::disk($disk)->put($path, $request->getContent());

        return response()->noContent();
    }
}
