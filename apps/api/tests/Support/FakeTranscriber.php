<?php

namespace Tests\Support;

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use App\Services\Transcription\Transcriber;

/**
 * Controllable Transcriber for pipeline tests: configurable availability, a fixed
 * result, or a forced failure. Records whether transcribe() ran.
 */
class FakeTranscriber implements Transcriber
{
    public bool $transcribed = false;

    public function __construct(
        private readonly bool $available = true,
        private readonly ?TranscriptionResult $result = null,
        private readonly bool $throws = false,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function transcribe(string $wavPath): TranscriptionResult
    {
        $this->transcribed = true;

        if ($this->throws) {
            throw new TranscriptionFailed('fake driver failure');
        }

        return $this->result ?? new TranscriptionResult(
            language: 'en',
            text: 'hello world',
            segments: [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'hello world']],
            driver: 'fake',
        );
    }
}
