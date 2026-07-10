<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/** Whisper transcription (T-018 replaces the no-op body). */
class TranscribeAudio extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'transcribe';
    }

    protected function queueName(): string
    {
        return 'media';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Fetching;
    }
}
