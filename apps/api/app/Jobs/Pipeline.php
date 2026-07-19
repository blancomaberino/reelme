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
     * The status a stage runs under (retry resets the share here). fetch→extract
     * happen while `fetching` — `extract` is the boundary job that itself advances
     * the share fetching → analyzing, so it must re-enter at `fetching` to match
     * ExtractPlaceData::expectedStatus(). Only resolve→publish run under `analyzing`.
     */
    public static function entryStatus(string $stage): ShareStatus
    {
        return in_array($stage, ['resolve', 'publish'], true)
            ? ShareStatus::Analyzing
            : ShareStatus::Fetching;
    }

    /**
     * Job instances for the full chain, or from `$fromStage` onward (retry).
     *
     * `$forceExtract` (admin reprocess, T-072) makes the ExtractPlaceData stage
     * skip its succeeded-run reuse so the LLM genuinely re-runs on a share that
     * already has a prior extraction — the only stage the flag affects.
     *
     * @return array<int, object>
     */
    public static function chain(int $shareId, ?string $fromStage = null, bool $forceExtract = false): array
    {
        $stages = array_keys(self::STAGES);

        if ($fromStage !== null) {
            $offset = array_search($fromStage, $stages, true);
            $stages = $offset === false ? $stages : array_slice($stages, (int) $offset);
        }

        return array_map(
            fn (string $stage): object => $stage === 'extract'
                ? new ExtractPlaceData($shareId, $forceExtract)
                : new (self::STAGES[$stage])($shareId),
            $stages,
        );
    }
}
