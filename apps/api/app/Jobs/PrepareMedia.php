<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/** ffmpeg keyframes/audio extraction (T-017 replaces the no-op body). */
class PrepareMedia extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'prepare';
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
