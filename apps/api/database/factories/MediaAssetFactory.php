<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\SourcePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_post_id' => SourcePost::factory(),
            'kind' => MediaKind::Video,
            'storage_path' => 'media/'.fake()->uuid().'/original/'.hash('sha256', fake()->uuid()).'.mp4',
            'disk' => 's3',
            'mime' => 'video/mp4',
            'bytes' => fake()->numberBetween(100_000, 50_000_000),
            'sha256' => hash('sha256', fake()->unique()->uuid()),
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'kind' => MediaKind::Video,
            'mime' => 'video/mp4',
            'duration_ms' => fake()->numberBetween(5_000, 90_000),
            'width' => 1080,
            'height' => 1920,
        ]);
    }

    public function keyframe(): static
    {
        $ms = fake()->numberBetween(0, 60_000);

        return $this->state(fn () => [
            'kind' => MediaKind::Keyframe,
            'mime' => 'image/jpeg',
            // Override the video default: a keyframe is a .jpg under frames/, not
            // an .mp4 under original/ — keep the fixture consistent with its mime.
            'storage_path' => 'media/'.fake()->uuid()."/frames/frame_0_{$ms}.jpg",
            'width' => 1080,
            'height' => 1920,
            'frame_at_ms' => $ms,
        ]);
    }
}
