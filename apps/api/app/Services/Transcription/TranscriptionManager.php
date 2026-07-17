<?php

namespace App\Services\Transcription;

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use Illuminate\Support\Facades\Log;

/**
 * Runs transcription local-first with a hosted fallback (04 §1/§3). The primary
 * driver is skipped when unavailable or after it throws; hosted is tried only
 * when it too is available. Both exhausted → TranscriptionFailed, which
 * TranscribeAudio treats as best-effort: it degrades to an empty transcript and
 * lets the pipeline continue (caption + keyframes still drive extraction), rather
 * than failing the share. Logs which driver won.
 */
class TranscriptionManager
{
    public function __construct(
        private readonly Transcriber $primary,
        private readonly HostedTranscriber $hosted,
    ) {}

    public function transcribe(string $wavPath, int $shareId): TranscriptionResult
    {
        foreach ($this->drivers() as $name => $driver) {
            if (! $driver->isAvailable()) {
                continue;
            }

            try {
                $result = $driver->transcribe($wavPath);
                Log::info('transcription.completed', ['share_id' => $shareId, 'driver' => $name]);

                return $result;
            } catch (TranscriptionFailed $e) {
                Log::warning('transcription.driver_failed', ['share_id' => $shareId, 'driver' => $name, 'error' => $e->getMessage()]);
            }
        }

        throw new TranscriptionFailed("No transcription driver succeeded for share {$shareId}.");
    }

    /**
     * @return array<string, Transcriber>
     */
    private function drivers(): array
    {
        return ['primary' => $this->primary, 'hosted' => $this->hosted];
    }
}
