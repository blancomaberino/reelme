<?php

namespace Database\Factories;

use App\Enums\TagKind;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'kind' => TagKind::Other,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    public function ofKind(TagKind $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }
}
