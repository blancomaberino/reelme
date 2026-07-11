<?php

namespace App\Services\Transcription\Data;

/**
 * A speech transcript (04 §1). `segments` carry per-utterance start/end ms so the
 * T-021 prompt can quote timed evidence. `empty` marks silent/music-only audio —
 * a valid, non-failing outcome. Persisted verbatim to source_posts.transcript_json.
 */
final readonly class TranscriptionResult
{
    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     */
    public function __construct(
        public ?string $language,
        public string $text,
        public array $segments,
        public string $driver,
        public bool $empty = false,
    ) {}

    public static function empty(string $driver): self
    {
        return new self(language: null, text: '', segments: [], driver: $driver, empty: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'text' => $this->text,
            'segments' => $this->segments,
            'driver' => $this->driver,
            'empty' => $this->empty,
        ];
    }
}
