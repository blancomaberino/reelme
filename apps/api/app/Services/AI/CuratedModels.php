<?php

namespace App\Services\AI;

/**
 * Read access to the curated OpenRouter allowlist in config/ai.php. Centralizes
 * lookups (by id, cheapest, pricing, json-schema support) so the client, the
 * router's cost cap, and the models endpoint agree on one source of truth.
 */
class CuratedModels
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> $models */
        $models = (array) config('ai.openrouter.curated_models', []);

        return array_values($models);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $model) {
            if (($model['id'] ?? null) === $id) {
                return $model;
            }
        }

        return null;
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    public function supportsJsonSchema(string $id): bool
    {
        return (bool) ($this->find($id)['supports_json_schema'] ?? false);
    }

    /**
     * Cheapest curated model by prompt price (the cost-cap downgrade target).
     *
     * @return array<string, mixed>|null
     */
    public function cheapest(): ?array
    {
        $models = $this->all();
        if ($models === []) {
            return null;
        }

        usort($models, fn (array $a, array $b): int => ($a['price_prompt_per_mtok'] ?? 0) <=> ($b['price_prompt_per_mtok'] ?? 0));

        return $models[0];
    }

    /**
     * Estimate a run's USD cost for a model from token counts and its per-Mtok
     * prices. Unknown model → 0 (cannot cap what we can't price; caller decides).
     */
    public function estimateCost(string $id, int $promptTokens, int $completionTokens): float
    {
        $model = $this->find($id);
        if ($model === null) {
            return 0.0;
        }

        $prompt = ((float) ($model['price_prompt_per_mtok'] ?? 0)) * $promptTokens / 1_000_000;
        $completion = ((float) ($model['price_completion_per_mtok'] ?? 0)) * $completionTokens / 1_000_000;

        return round($prompt + $completion, 6);
    }
}
