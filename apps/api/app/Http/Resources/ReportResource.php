<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report, as its author sees it back (T-049, 03 §2.16).
 *
 * Deliberately thin. The reporter gets a receipt — enough to know it landed and
 * what they said — and nothing about triage: no resolver, no internal notes, no
 * count of other reports against the same target. All of that would tell a
 * malicious reporter exactly how close they are to getting something removed.
 *
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reportable_type' => $this->reportable_type,
            'reportable_id' => (string) $this->reportable_id,
            'reason' => $this->reason->value,
            'details' => $this->details,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
