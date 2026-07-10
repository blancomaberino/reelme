<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/** Place dedup/geocode resolution (T-023 replaces the no-op body). */
class ResolvePlace extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'resolve';
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
