<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use App\Services\Places\PlaceEditor;
use Database\Factories\PlaceEditSuggestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proposed correction to a place's business info (T-083) — "the phone number
 * is wrong", from anyone signed in.
 *
 * This table holds *proposals*; {@see PlaceEdit} still holds what was applied,
 * and an approved row points at the edit it produced. `changes` is the same
 * `{field: {from, to}}` diff shape, captured at submit time: `from` is what the
 * submitter was looking at, which is how a reviewer spots a proposal that the
 * place has already moved past.
 *
 * A row may also carry a free-text `note` (T-112) — "this place closed down",
 * everything the five-field form cannot express. A note-only row is valid and
 * stores an empty `changes`, so every renderer here has to survive an empty
 * diff. On disk that is `[]` rather than `{}`: the column goes through the
 * `array` cast, and PHP encodes an empty array as a JSON list.
 *
 * @property int $id
 * @property int $place_id
 * @property int|null $user_id
 *                             Note the loose `$changes` type. The intended shape is
 *                             `{field: {from, to}}` — what {@see PlaceEditor::diff()} produces and what
 *                             {@see PlaceEdit} declares — but this column is jsonb read back through an
 *                             `array` cast, so what comes OUT is whatever is in the row. Declaring the
 *                             precise shape would make PHPStan prove the runtime guards in `patch()` and in
 *                             the two renderers unreachable, and deleting them would leave a legacy or
 *                             hand-edited row crashing a moderation queue. The shape is asserted where it
 *                             is written, and re-checked where it is read.
 * @property array<string, mixed> $changes
 * @property string|null $note
 * @property SuggestionStatus $status
 * @property bool $is_owner_submission
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $reason
 * @property int|null $place_edit_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PlaceEditSuggestion extends Model
{
    /** @use HasFactory<PlaceEditSuggestionFactory> */
    use HasFactory;

    /**
     * The curated fields a *suggestion* may propose — a strict subset of
     * {@see Place::CURATED_FIELDS}, and the only allow-list either path
     * validates against.
     *
     * The picture fields (`image_url`, `thumbnail_url`, `gallery_json`) are
     * deliberately absent. They are URLs the app renders as the venue's hero and
     * as its map marker, so accepting one from an arbitrary signed-in stranger
     * is an image-injection surface with a moderation step that only ever sees
     * a link, not what it will serve tomorrow. Pictures stay a curator/enrichment
     * concern (T-084/T-099).
     *
     * @var list<string>
     */
    public const FIELDS = [
        'name', 'address_line1', 'address_line2', 'city', 'region', 'postal_code',
        'country_code', 'cuisine_primary', 'price_range', 'phone', 'website',
        'opening_hours_json',
    ];

    protected $fillable = [
        'place_id', 'user_id', 'changes', 'note', 'status', 'is_owner_submission',
        'reviewed_by_user_id', 'reviewed_at', 'reason', 'place_edit_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'status' => SuggestionStatus::class,
            'is_owner_submission' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * How long a submitter's note may be (T-112).
     *
     * The same 2000 as `reports.details`, deliberately: it is the other
     * free-text box on the same screen, and a limit that differed between them
     * would be a limit somebody hit by choosing the "wrong" one.
     */
    public const NOTE_MAX = 2000;

    /** Still somebody's work. */
    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }

    /** Did the submitter write something a field diff cannot say? (T-112) */
    public function hasNote(): bool
    {
        return $this->note !== null && $this->note !== '';
    }

    /**
     * A note with no applicable field patch — the shape the `Actioned` verb
     * exists for.
     *
     * Asked of {@see patch()} rather than of the raw `changes` column, so a row
     * whose only proposed field is one the allow-list rejects is treated as what
     * it actually is: prose plus nothing that can be applied.
     */
    public function isNoteOnly(): bool
    {
        return $this->hasNote() && $this->patch() === [];
    }

    /**
     * The flat patch this suggestion proposes — `{field: to}`, which is what
     * {@see PlaceEditor::apply()} takes. Derived rather than
     * stored a second time: two copies of the same proposal is one copy too many.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = [];

        // `getAttribute()`, NOT `$this->changes`. Eloquent's HasAttributes
        // declares `protected $changes` (the dirty-attribute tracker), so INSIDE
        // the model that property wins over `__get` and the column is silently
        // invisible — every read comes back as the empty tracker array, and a
        // suggestion applies nothing at all. From outside the model the property
        // is inaccessible and `__get` reaches the column as normal, which is why
        // the resource and the Filament table can spell it the short way.
        /** @var array<string, mixed> $changes */
        $changes = $this->getAttribute('changes') ?? [];

        foreach ($changes as $field => $change) {
            if (is_array($change) && array_key_exists('to', $change)) {
                $patch[$field] = $change['to'];
            }
        }

        // Confined HERE rather than only at submit time. `changes` is a jsonb
        // column, and everything that applies a suggestion goes through this
        // method — so a row that got into the table by another route (a console
        // command, an import, a future surface) still cannot write a field the
        // allow-list excludes. PlaceEditor's own filter is the wider *curated*
        // set, which includes the picture URLs a suggestion may never propose.
        // The same argument applies to the VALUE's SHAPE, but NOT here: this is a
        // read accessor (`isNoteOnly()` and the moderation renderers call it), so
        // coercing in it would change what a reviewer is shown, and it is only
        // ONE of the two paths that apply a patch — the operator fast path in
        // `PlaceSuggestionService::submit()` never comes through here.
        // {@see PlaceEditor::apply()} is the line both cross, and that is where
        // the shape is normalized.
        return array_intersect_key($patch, array_flip(self::FIELDS));
    }

    /**
     * @param  Builder<PlaceEditSuggestion>  $query
     * @return Builder<PlaceEditSuggestion>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SuggestionStatus::Pending);
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * The audit row an approval produced, if it changed anything.
     *
     * @return BelongsTo<PlaceEdit, $this>
     */
    public function placeEdit(): BelongsTo
    {
        return $this->belongsTo(PlaceEdit::class);
    }
}
