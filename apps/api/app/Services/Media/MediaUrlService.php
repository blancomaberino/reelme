<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The single seam pipeline code uses for signed media URLs. Callers never touch
 * Storage or branch on driver — this class hides the s3 (R2 presigned) vs local
 * (signed route) difference so M1 jobs stay driver-agnostic.
 */
class MediaUrlService
{
    /**
     * Short-lived signed GET URL. Works uniformly: R2 presigns; the local disks
     * use `serve => true` so Laravel signs a local route.
     */
    public function temporaryUrl(string $path, ?string $disk = null, ?int $minutes = null): string
    {
        $disk ??= config('media.disk');
        $minutes ??= (int) config('media.get_url_ttl');

        return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }

    /**
     * Signed URL the client PUTs the file to. On s3/R2 this is a native
     * presigned upload; in local dev it's a signed route handled by
     * MediaUploadController.
     *
     * @return array{url: string, headers: array<string, string>, method: string}
     */
    public function temporaryUploadUrl(string $path, ?string $disk = null, ?int $minutes = null): array
    {
        $disk ??= config('media.disk');
        $minutes ??= (int) config('media.put_url_ttl');
        $expiresAt = now()->addMinutes($minutes);

        if ($this->driverFor($disk) === 's3') {
            $signed = Storage::disk($disk)->temporaryUploadUrl($path, $expiresAt);

            return [
                'url' => $signed['url'],
                'headers' => $signed['headers'],
                'method' => 'PUT',
            ];
        }

        // Local dev: a signed route the client PUTs the raw body to.
        return [
            'url' => URL::temporarySignedRoute('media.upload', $expiresAt, ['disk' => $disk, 'path' => $path]),
            'headers' => [],
            'method' => 'PUT',
        ];
    }

    private function driverFor(string $disk): ?string
    {
        return config("filesystems.disks.{$disk}.driver");
    }
}
