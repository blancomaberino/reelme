<?php

namespace App\Jobs;

use App\Enums\ShareStatus;

/** Downloads original media (T-017 replaces the no-op body). */
class DownloadMedia extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'download';
    }

    protected function queueName(): string
    {
        return 'ingest';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Fetching;
    }
}
