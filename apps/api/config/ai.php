<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local engine — Ollama
    |--------------------------------------------------------------------------
    |
    | The pipeline is local-first (ADR-005): every extraction attempts Ollama
    | before falling back to a hosted model. `url` is reachable from the queue
    | workers — inside Sail that is host.docker.internal, not localhost.
    */

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'vision_model' => env('OLLAMA_VISION_MODEL', 'qwen2.5-vl:7b'),
        'text_model' => env('OLLAMA_TEXT_MODEL', 'qwen2.5:14b'),
        // Generation timeout (a full multimodal extraction is slow).
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
        // Health probe: short, and never blocks the pipeline.
        'health_timeout' => (int) env('OLLAMA_HEALTH_TIMEOUT', 2),
        'health_cache_seconds' => (int) env('OLLAMA_HEALTH_CACHE_SECONDS', 30),
        // Guard against a dead host eating the full generation timeout.
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote engine — OpenRouter (wired in T-020)
    |--------------------------------------------------------------------------
    |
    | Placeholders only for now: T-019 binds a NullRemoteEngine that always
    | throws EngineUnavailable so the fallback seam is exercised end to end.
    */

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'google/gemini-2.0-flash-001'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),
        // Curated, vision-capable, JSON-reliable models the picker may offer.
        'curated_models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost caps
    |--------------------------------------------------------------------------
    */

    'max_cost_per_run' => (float) env('AI_MAX_COST_PER_RUN', 0.10),
    'daily_user_budget' => (float) env('AI_DAILY_USER_BUDGET', 0.50),

    // Confidence below this escalates a local result to the remote engine (04 §3).
    'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.5),
];
