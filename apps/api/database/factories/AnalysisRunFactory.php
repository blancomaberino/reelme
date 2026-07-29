<?php

namespace Database\Factories;

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Share;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisRun>
 */
class AnalysisRunFactory extends Factory
{
    protected $model = AnalysisRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'share_id' => Share::factory(),
            'engine' => AnalysisEngine::Local,
            'model' => 'qwen2.5-vl:7b',
            'status' => AnalysisStatus::Queued,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Succeeded,
            'started_at' => now()->subSeconds(20),
            'finished_at' => now(),
            'input_tokens' => fake()->numberBetween(500, 4000),
            'output_tokens' => fake()->numberBetween(100, 1200),
            'cost_usd' => '0.000000',
            'overall_confidence' => fake()->randomFloat(3, 0.5, 1.0),
            'result_json' => ['place' => ['name' => fake()->company()]],
        ]);
    }

    /**
     * A completed OpenRouter run. Coherent standalone — a run that incurred
     * cost has run to completion, so this sets a Succeeded status and timestamps
     * alongside the engine/model/cost. Compose with `->failed()` to model a
     * failed-but-billed run (keeps the cost, overrides status/timestamps).
     */
    public function openrouter(): static
    {
        return $this->state(fn () => [
            'engine' => AnalysisEngine::OpenRouter,
            'model' => 'anthropic/claude-sonnet',
            'status' => AnalysisStatus::Succeeded,
            'started_at' => now()->subSeconds(15),
            'finished_at' => now(),
            'cost_usd' => (string) fake()->randomFloat(6, 0.001, 0.05),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Failed,
            'started_at' => now()->subSeconds(10),
            'finished_at' => now(),
            'error' => fake()->sentence(),
        ]);
    }
}
