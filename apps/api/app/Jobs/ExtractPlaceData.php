<?php

namespace App\Jobs;

use App\Enums\ShareStatus;
use App\Models\Share;

/**
 * Multimodal extraction (T-021 replaces the no-op body). As the first analysis
 * stage it advances the share fetching → analyzing.
 */
class ExtractPlaceData extends PipelineStubJob
{
    protected function stage(): string
    {
        return 'extract';
    }

    protected function queueName(): string
    {
        return 'analysis';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Fetching;
    }

    protected function run(Share $share): void
    {
        $share->transitionTo(ShareStatus::Analyzing);
    }
}
