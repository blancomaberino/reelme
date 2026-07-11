<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\EngineUnavailable;

/**
 * Builds the merged model catalog the mobile picker renders (04 §3): `auto`
 * first, then live vision-capable Ollama models (free), then the curated
 * OpenRouter allowlist with pricing. Also validates a preference id against that
 * live+curated set. Ollama being down degrades gracefully to an empty local
 * section — never an error.
 */
class ModelCatalog
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly CuratedModels $curated,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            ['id' => 'auto', 'display_name' => 'Auto (recommended)', 'engine' => 'auto', 'cost_class' => 'free', 'default' => true],
            ...$this->localEntries(),
            ...$this->remoteEntries(),
        ];
    }

    /**
     * @return list<string>
     */
    public function selectableIds(): array
    {
        return array_map(static fn (array $m): string => (string) $m['id'], $this->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localEntries(): array
    {
        try {
            $models = $this->ollama->listModels();
        } catch (EngineUnavailable) {
            return []; // Ollama down → no local section, no error.
        }

        $entries = [];
        foreach ($models as $model) {
            if (! $this->isVisionCapable($model['name'])) {
                continue;
            }
            $entries[] = [
                'id' => $model['name'],
                'display_name' => $model['name'],
                'engine' => 'local',
                'cost_class' => 'free',
                'default' => false,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function remoteEntries(): array
    {
        $entries = [];
        foreach ($this->curated->all() as $model) {
            $entries[] = [
                'id' => $model['id'],
                'display_name' => $model['display_name'] ?? $model['id'],
                'engine' => 'openrouter',
                'cost_class' => $model['cost_class'] ?? 'standard',
                'price_prompt_per_mtok' => $model['price_prompt_per_mtok'] ?? null,
                'price_completion_per_mtok' => $model['price_completion_per_mtok'] ?? null,
                'default' => false,
            ];
        }

        return $entries;
    }

    /** Vision-capable if the tag matches a configured vision family/name. */
    private function isVisionCapable(string $name): bool
    {
        foreach ((array) config('ai.ollama.vision_tags', ['vl', 'vision', 'llava', 'llama3.2-vision']) as $needle) {
            if (str_contains(strtolower($name), strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
