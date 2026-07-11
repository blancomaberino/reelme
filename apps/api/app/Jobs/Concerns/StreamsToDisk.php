<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Upload a local file to a disk via a stream that is always closed — even if
 * writeStream() throws — so a long-lived worker never leaks file descriptors.
 */
trait StreamsToDisk
{
    protected function writeStreamFromFile(string $disk, string $storagePath, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        try {
            Storage::disk($disk)->writeStream($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
