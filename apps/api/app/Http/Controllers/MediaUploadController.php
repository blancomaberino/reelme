<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Handles PUT uploads to a signed local-dev media URL (see MediaUrlService).
 * The `signed` middleware validates the URL. Guarded to local-driver disks so
 * it can never be pointed at R2 — production uses native presigned R2 uploads.
 */
class MediaUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $disk = (string) $request->query('disk');
        $path = (string) $request->query('path');

        abort_unless(config("filesystems.disks.{$disk}.driver") === 'local', 404);

        Storage::disk($disk)->put($path, $request->getContent());

        return response()->json([
            'data' => ['stored' => true, 'path' => $path],
            'meta' => (object) [],
        ]);
    }
}
