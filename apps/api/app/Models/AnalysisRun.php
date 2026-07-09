<?php

namespace App\Models;

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisRun extends Model
{
    /** @use HasFactory<AnalysisRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

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
