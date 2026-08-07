<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Share;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $share = Share::factory()->create();

        return [
            'reporter_user_id' => User::factory(),
            // getMorphClass(), never Share::class — the column holds the alias,
            // and a fixture that writes the FQCN agrees only with a query that
            // makes the same mistake.
            'reportable_type' => $share->getMorphClass(),
            'reportable_id' => $share->id,
            'reason' => ReportReason::Spam,
            'details' => null,
            'status' => ReportStatus::Open,
        ];
    }

    /** A report against a specific thing. */
    public function against(Model $target): static
    {
        return $this->state(fn () => [
            'reportable_type' => $target->getMorphClass(),
            'reportable_id' => $target->getKey(),
        ]);
    }

    public function reason(ReportReason $reason): static
    {
        return $this->state(fn () => ['reason' => $reason]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Resolved,
            'resolved_by_user_id' => User::factory()->admin(),
            'resolved_at' => now(),
        ]);
    }
}
