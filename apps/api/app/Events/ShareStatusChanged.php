<?php

namespace App\Events;

use App\Enums\ShareStatus;
use App\Models\Share;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a share status transition persists (03 §4.2). Client polling of
 * GET /shares/{id} observes the change; push-notification listeners on
 * review/published/failed arrive in T-027.
 */
class ShareStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Share $share,
        public readonly ShareStatus $from,
        public readonly ShareStatus $to,
    ) {}
}
