<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/**
 * Single source of truth for the pipeline stage order — used by both chain
 * assembly (IngestShare) and retry (ShareController). Each stage maps to a job
 * class and the share status it re-enters at (for retry).
 */
final class Pipeline
{
    /**
     * Ordered stages after IngestShare. `stage key => job class`.
     *
     * @var array<string, class-string>
     */
    public const STAGES = [
        'fetch' => FetchSourcePost::class,
        'download' => DownloadMedia::class,
        'prepare' => PrepareMedia::class,
        'transcribe' => TranscribeAudio::class,
        'extract' => ExtractPlaceData::class,
        'resolve' => ResolvePlace::class,
        'publish' => PublishShare::class,
    ];

    /**
     * The status a stage runs under (retry resets the share here). fetch→transcribe
     * happen while `fetching`; extract→publish while `analyzing`.
     */
    public static function entryStatus(string $stage): ShareStatus
    {
        return in_array($stage, ['extract', 'resolve', 'publish'], true)
            ? ShareStatus::Analyzing
            : ShareStatus::Fetching;
    }

    /**
     * Job instances for the full chain, or from `$fromStage` onward (retry).
     *
     * @return array<int, object>
     */
    public static function chain(int $shareId, ?string $fromStage = null): array
    {
        $stages = array_keys(self::STAGES);

        if ($fromStage !== null) {
            $offset = array_search($fromStage, $stages, true);
            $stages = $offset === false ? $stages : array_slice($stages, (int) $offset);
        }

        return array_map(fn (string $stage) => new (self::STAGES[$stage])($shareId), $stages);
    }
}
