<?php

namespace App\Services\Transcription;

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;

/**
 * A pluggable speech-to-text backend (04 §1). Local (whisper.cpp) and hosted
 * drivers implement this; the TranscriptionManager picks local-first and falls
 * back to hosted. `isAvailable()` must fail soft (a missing binary/model is the
 * fallback trigger, not an error).
 */
interface Transcriber
{
    public function isAvailable(): bool;

    /**
     * @throws TranscriptionFailed
     */
    public function transcribe(string $wavPath): TranscriptionResult;
}
