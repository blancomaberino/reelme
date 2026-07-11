<?php

namespace App\Services\AI\Prompts;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Support\Contracts\ExtractionSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Assembles the multimodal extraction prompt from a share's fetched inputs
 * (04 §5). User parts are built in a fixed order — each keyframe preceded by a
 * `frame {i} @ {mm:ss}` label, then CAPTION, then TRANSCRIPT, then the inline
 * schema and final instruction. Keyframe order defines the `frame_refs` indexes
 * (0..N-1), so it must match the ordered keyframe assets exactly.
 *
 * Keyframe bytes are read lazily and base64-encoded only here; the resulting
 * request holds several MB and is never logged or persisted (T-019 gotchas).
 */
class ExtractionPromptBuilder
{
    /** Max keyframes the schema addresses (frame_refs maximum is 11 → 12 frames). */
    private const MAX_FRAMES = 12;

    public function build(Share $share): GenerationRequest
    {
        [$systemPrompt, $version] = $this->system();

        $parts = [];
        foreach ($this->keyframes($share) as $index => $keyframe) {
            $parts[] = GenerationPart::text("frame {$index} @ {$this->timecode($keyframe->frame_at_ms)}");
            $parts[] = GenerationPart::image(
                base64_encode((string) Storage::disk($keyframe->disk)->get($keyframe->storage_path)),
                $keyframe->mime ?: 'image/jpeg',
            );
        }

        $parts[] = GenerationPart::text("CAPTION:\n".$this->caption($share));
        $parts[] = GenerationPart::text("TRANSCRIPT:\n".$this->transcript($share));
        $parts[] = GenerationPart::text($this->schemaJson());
        $parts[] = GenerationPart::text('Respond with a single JSON object valid against the schema above.');

        return new GenerationRequest(
            systemPrompt: $systemPrompt,
            userParts: $parts,
            jsonSchema: $this->schemaArray(),
            temperature: 0.0,
            promptVersion: $version,
        );
    }

    /**
     * Ordered keyframes for the share's source post (chronological by capture
     * timestamp), capped at the count the schema can reference.
     *
     * @return Collection<int, MediaAsset>
     */
    private function keyframes(Share $share): Collection
    {
        return $share->sourcePost->mediaAssets()
            ->where('kind', MediaKind::Keyframe->value)
            ->orderBy('frame_at_ms')
            ->orderBy('id')
            ->limit(self::MAX_FRAMES)
            ->get()
            ->values();
    }

    private function caption(Share $share): string
    {
        $caption = trim((string) $share->sourcePost->caption);

        return $caption !== '' ? $caption : '(none)';
    }

    /**
     * Flatten the stored transcript into `[start–end] text` lines. A silent or
     * absent transcript collapses to a marker so the model isn't led to invent.
     */
    private function transcript(Share $share): string
    {
        $transcript = $share->sourcePost->transcript_json;

        if (! is_array($transcript) || ($transcript['empty'] ?? false) === true) {
            return '(no speech)';
        }

        $segments = is_array($transcript['segments'] ?? null) ? $transcript['segments'] : [];
        if ($segments === []) {
            $text = trim((string) ($transcript['text'] ?? ''));

            return $text !== '' ? $text : '(no speech)';
        }

        $lines = [];
        foreach ($segments as $segment) {
            $start = $this->timecode((int) ($segment['start_ms'] ?? 0));
            $end = $this->timecode((int) ($segment['end_ms'] ?? 0));
            $lines[] = "[{$start}–{$end}] ".trim((string) ($segment['text'] ?? ''));
        }

        return implode("\n", $lines);
    }

    /**
     * Load the versioned system prompt, stripping the leading version marker.
     *
     * @return array{0: string, 1: string} [prompt body, version]
     */
    private function system(): array
    {
        $raw = (string) file_get_contents(resource_path('prompts/extraction.system.md'));

        $version = 'unknown';
        if (preg_match('/<!--\s*prompt-version:\s*(\S+)\s*-->/', $raw, $matches) === 1) {
            $version = $matches[1];
        }

        $body = trim(preg_replace('/<!--\s*prompt-version:.*?-->\s*/', '', $raw, 1) ?? $raw);

        return [$body, $version];
    }

    private function schemaJson(): string
    {
        return (string) file_get_contents(ExtractionSchema::path());
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaArray(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->schemaJson(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** Milliseconds → `mm:ss`. */
    private function timecode(?int $ms): string
    {
        $totalSeconds = intdiv(max(0, (int) $ms), 1000);

        return sprintf('%02d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60);
    }
}
