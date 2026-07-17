<?php

use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Jobs\TranslateTag;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

// --- Access control ---

it('forbids non-admins from the Tags resource', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/admin/tags')->assertForbidden();
});

it('lists tags for an admin, showing the localized label', function () {
    $this->actingAs(User::factory()->admin()->create());
    $casual = Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);
    $untranslated = Tag::factory()->create(['name' => 'kombucha', 'slug' => 'kombucha', 'name_i18n' => null]);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$casual, $untranslated])
        ->assertSee('Informal');
});

it('filters to tags missing a Spanish label', function () {
    $this->actingAs(User::factory()->admin()->create());
    $translated = Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);
    $missing = Tag::factory()->create(['name' => 'kombucha', 'slug' => 'kombucha', 'name_i18n' => null]);

    Livewire::test(ListTags::class)
        ->filterTable('untranslated')
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$translated]);
});

it('lets an admin override the Spanish label', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    Livewire::test(EditTag::class, ['record' => $tag->getKey()])
        ->fillForm(['name_i18n' => ['es' => 'Relajado']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Relajado']);
});

it('clears name_i18n to null when the override is blanked (falls back to English)', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    Livewire::test(EditTag::class, ['record' => $tag->getKey()])
        ->fillForm(['name_i18n' => ['es' => '']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name_i18n)->toBeNull()
        ->and($tag->fresh()->localizedName('es'))->toBe('casual');
});

it('queues a re-translation from the row action', function () {
    Bus::fake();
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create(['name' => 'kombucha', 'slug' => 'kombucha', 'name_i18n' => null]);

    Livewire::test(ListTags::class)
        ->callAction(TestAction::make('retranslate')->table($tag));

    Bus::assertDispatched(TranslateTag::class, fn (TranslateTag $job): bool => $job->tagId === $tag->id);
});
