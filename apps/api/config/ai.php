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
        // Substrings that mark a local tag as vision-capable for the models
        // endpoint (only these are offered to the multimodal picker).
        'vision_tags' => ['vl', 'vision', 'llava'],
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
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 180),
        // OpenRouter attribution headers (their convention for app ranking).
        'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'https://reelmap.app')),
        'title' => env('OPENROUTER_TITLE', 'Reelmap'),

        // Curated allowlist — vision-capable, JSON-reliable models the picker may
        // offer (04 §3). Prices are USD per million tokens. `supports_json_schema`
        // gates strict structured-output mode; the pipeline schema-validates the
        // output downstream regardless. Kept small and curated so the picker never
        // offers a model the pipeline can't drive.
        'curated_models' => [
            [
                'id' => 'google/gemini-2.0-flash-001',
                'display_name' => 'Gemini 2.0 Flash',
                'supports_json_schema' => true,
                'price_prompt_per_mtok' => 0.10,
                'price_completion_per_mtok' => 0.40,
                'cost_class' => 'cheap',
            ],
            [
                'id' => 'openai/gpt-4o-mini',
                'display_name' => 'GPT-4o mini',
                'supports_json_schema' => true,
                'price_prompt_per_mtok' => 0.15,
                'price_completion_per_mtok' => 0.60,
                'cost_class' => 'cheap',
            ],
            [
                'id' => 'qwen/qwen2.5-vl-72b-instruct',
                'display_name' => 'Qwen2.5-VL 72B',
                'supports_json_schema' => false,
                'price_prompt_per_mtok' => 0.25,
                'price_completion_per_mtok' => 0.75,
                'cost_class' => 'standard',
            ],
            [
                'id' => 'anthropic/claude-sonnet-4',
                'display_name' => 'Claude Sonnet 4',
                'supports_json_schema' => true,
                'price_prompt_per_mtok' => 3.00,
                'price_completion_per_mtok' => 15.00,
                'cost_class' => 'premium',
            ],
        ],
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
