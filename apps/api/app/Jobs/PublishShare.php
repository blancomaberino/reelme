<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/** Publishes the share + activates the place (T-024 replaces the no-op body). */
class PublishShare extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'publish';
    }

    protected function queueName(): string
    {
        return 'analysis';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Analyzing;
    }
}
