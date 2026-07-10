<?php

namespace App\Services\AI\Data;

/**
 * One part of a multimodal user message: either text, or an image supplied as
 * raw base64 + mime. Images are built lazily and encoded only at send time — a
 * GenerationRequest holds several MB of keyframe base64, so it is never
 * persisted or logged (T-019 gotchas).
 */
final readonly class GenerationPart
{
    private function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $imageBase64 = null,
        public ?string $mime = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(type: 'text', text: $text);
    }

    public static function image(string $base64, string $mime = 'image/jpeg'): self
    {
        return new self(type: 'image', imageBase64: $base64, mime: $mime);
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }
}
