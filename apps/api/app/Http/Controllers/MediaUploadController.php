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
 * The body is streamed to disk under a hard byte cap (enforced on the bytes
 * received, not just the client's Content-Length), and a missing/invalid
 * Content-Length is refused. Production uses native presigned R2 uploads and
 * never this route.
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

        $max = (int) config('media.max_upload_bytes');

        // Require a declared length. A chunked / absent Content-Length would let a
        // client stream a body of unknown size, so we could only discover it blew
        // the cap after buffering it — refuse it outright (411 Length Required).
        $declared = $request->header('Content-Length');
        abort_if($declared === null || ! ctype_digit((string) $declared), 411);
        abort_if($max > 0 && (int) $declared > $max, 413);

        // The declared length is client-supplied, so also cap the bytes actually
        // received. Copy into a temp handle (spills to disk past a few MB) rather
        // than buffering the whole body into memory, reading at most max+1 bytes so
        // an over-cap body is rejected without slurping all of it.
        $buffer = fopen('php://temp', 'r+b');
        $copied = stream_copy_to_stream($request->getContent(asResource: true), $buffer, $max > 0 ? $max + 1 : -1);

        if ($max > 0 && $copied > $max) {
            fclose($buffer);
            abort(413);
        }

        rewind($buffer);
        Storage::disk($disk)->writeStream($path, $buffer);
        fclose($buffer);

        return response()->noContent();
    }
}
