<?php

namespace App\Models;

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $share_id
 * @property AnalysisEngine $engine
 * @property string $model
 * @property AnalysisStatus $status
 * @property string|null $cost_usd
 * @property string|null $overall_confidence
 * @property array<string, mixed>|null $result_json
 */
class AnalysisRun extends Model
{
    /** @use HasFactory<AnalysisRunFactory> */
    use HasFactory;

    // Written only by the analysis pipeline (ModelRouter/jobs, T-019+), never
    // from user request input — `result_json`/`cost_usd` are trust-sensitive.
    protected $fillable = [
        'share_id', 'engine', 'model', 'prompt_version', 'status', 'started_at', 'finished_at',
        'input_tokens', 'output_tokens', 'cost_usd', 'overall_confidence',
        'result_json', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engine' => AnalysisEngine::class,
            'status' => AnalysisStatus::class,
            'result_json' => 'array',
            'cost_usd' => 'decimal:6',
            'overall_confidence' => 'decimal:3',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Share, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
