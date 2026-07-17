<?php

use App\Models\Influencer;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Prompts\ExtractionPromptBuilder;

/**
 * The extraction prompt must hand the model the POSTING ACCOUNT and instruct it
 * that the poster is the reviewer, not a venue (extraction.v10). Regression for
 * the @el_encantador_de_burgas reel, where the reviewer's branded cover frame
 * got read as the venue name and the actually-reviewed @lagranburgerok (named in
 * the caption) was missed.
 */
function promptFor(string $caption, ?Influencer $influencer): GenerationRequest
{
    $post = SourcePost::factory()->create([
        'influencer_id' => $influencer?->id,
        'caption' => $caption,
    ]);
    $share = Share::factory()->create(['source_post_id' => $post->id]);

    return app(ExtractionPromptBuilder::class)->build($share);
}

/** All text user-parts joined — the textual prompt the model sees. */
function promptText(GenerationRequest $req): string
{
    return collect($req->userParts)
        ->filter(fn ($p) => $p->type === 'text')
        ->map(fn ($p) => (string) $p->text)
        ->implode("\n");
}

it('injects the posting account and ships the v9 poster-exclusion rule', function () {
    $reviewer = Influencer::factory()->create([
        'handle' => 'el_encantador_de_burgas',
        'display_name' => 'El Encantador de Burgas',
    ]);
    $req = promptFor('En la 13 visitamos a @lagranburgerok y cerramos Canelones.', $reviewer);

    $text = promptText($req);

    // The prompt version bumped, so drift is recorded on the analysis run.
    expect($req->promptVersion)->toBe('extraction.v10');

    // The account is surfaced to the model — handle AND the informative display name.
    expect($text)->toContain('POSTED BY:')
        ->toContain('@el_encantador_de_burgas')
        ->toContain('El Encantador de Burgas');

    // The reviewed venue (caption @handle) is still present for the model to pick.
    expect($text)->toContain('@lagranburgerok');

    // The system prompt carries the rule that turns this signal into behavior.
    expect($req->systemPrompt)
        ->toContain('POSTING ACCOUNT')
        ->toContain('NEVER use it as a `places[].name`');
});

it('ships the v9 price-alignment + cuisine-nationality guardrails', function () {
    // Regression for the "Hugo" (Punta Carretas, Montevideo) post: a small vision
    // model borrowed/repeated menu prices across caption-derived dishes and tagged
    // the cuisine "argentinian" for a Uruguayan venue.
    $sys = promptFor('caption', null)->systemPrompt;

    expect($sys)
        // Price alignment: never borrow/repeat a price across dishes.
        ->toContain('do NOT borrow a price')
        ->toContain('repeat the same price across different dishes')
        // Cuisine nationality must match location/@handle, not dish style.
        ->toContain('NATIONALITY/country cuisine')
        ->toContain('is not "argentinian"');
});

it('omits a redundant display name equal to the handle', function () {
    $reviewer = Influencer::factory()->create([
        'handle' => 'el_encantador_de_burgas',
        'display_name' => 'el_encantador_de_burgas',
    ]);
    $req = promptFor('caption', $reviewer);

    // "@handle (display)" collapses to just "@handle" when they're the same.
    expect(promptText($req))->toContain("POSTED BY:\n@el_encantador_de_burgas\n")
        ->not->toContain('(el_encantador_de_burgas)');
});

it('falls back to the bare handle when the influencer has no display name', function () {
    $reviewer = Influencer::factory()->create(['handle' => 'burgerscout', 'display_name' => null]);
    $req = promptFor('caption', $reviewer);

    expect(promptText($req))->toContain("POSTED BY:\n@burgerscout\n");
});

it('marks the poster unknown for a post with no attributed influencer', function () {
    $req = promptFor('a manual caption share', null);

    expect(promptText($req))->toContain("POSTED BY:\n(unknown)");
});
