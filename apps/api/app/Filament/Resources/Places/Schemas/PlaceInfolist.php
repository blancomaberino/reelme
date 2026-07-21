<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Enums\PlaceStatus;
use App\Enums\TagKind;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Services\Places\PlaceDedupMatcher;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class PlaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Place')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (PlaceStatus $state): string => match ($state) {
                                PlaceStatus::Pending => 'warning',
                                PlaceStatus::Active => 'success',
                                PlaceStatus::Merged => 'gray',
                                PlaceStatus::Removed => 'gray',
                                PlaceStatus::Hidden => 'danger',
                            }),
                        TextEntry::make('mergedInto.name')
                            ->label('Merged into')
                            ->url(fn (Place $record): ?string => $record->merged_into_place_id !== null
                                ? PlaceResource::getUrl('view', ['record' => $record->merged_into_place_id])
                                : null)
                            ->color('primary')
                            ->visible(fn (Place $record): bool => $record->merged_into_place_id !== null),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('address_line1')->label('Address')->placeholder('—'),
                        TextEntry::make('city')->placeholder('—'),
                        TextEntry::make('region')->placeholder('—'),
                        TextEntry::make('postal_code')->placeholder('—'),
                        TextEntry::make('country_code')->label('Country'),
                        TextEntry::make('cuisine_primary')->label('Cuisine')->placeholder('—'),
                        TextEntry::make('price_range')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('website')->placeholder('—'),
                        TextEntry::make('google_place_id')->label('Google Place ID')->placeholder('—')->copyable(),
                        TextEntry::make('google_rating')
                            ->label('Google rating')
                            ->formatStateUsing(fn (Place $record): string => "★{$record->google_rating} ({$record->google_rating_count})")
                            ->placeholder('—'),
                        TextEntry::make('shares_count')->label('Sources'),
                        TextEntry::make('avg_extraction_confidence')->label('Avg extraction confidence')->placeholder('—'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Business & curation')
                    ->description('First-class business fields (T-084). Locked fields were hand-set by a human and are never overwritten by an enrichment or a re-share.')
                    ->columns(3)
                    ->schema([
                        ImageEntry::make('image_url')
                            ->label('Picture')
                            ->height(120)
                            ->placeholder('—')
                            ->visible(fn (Place $record): bool => $record->image_url !== null || $record->thumbnail_url !== null),
                        TextEntry::make('locked_fields')
                            ->label('Locked fields')
                            ->badge()
                            ->color('warning')
                            ->placeholder('none')
                            ->state(fn (Place $record): array => $record->lockedFields()),
                        TextEntry::make('enriched_at')
                            ->label('Last enriched')
                            ->dateTime()
                            ->placeholder('never'),
                    ]),
                Section::make('Edit history')
                    ->description('Audit trail of curated-field changes — manual edits, enrichment runs and system writes.')
                    ->schema([
                        RepeatableEntry::make('placeEdits')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('origin')->badge(),
                                TextEntry::make('user.name')->label('By')->placeholder('system'),
                                TextEntry::make('created_at')
                                    ->label('When')
                                    ->state(fn ($record): ?string => $record->created_at instanceof Carbon
                                        ? $record->created_at->diffForHumans()
                                        : null),
                                TextEntry::make('changes')
                                    ->label('Changes')
                                    ->columnSpanFull()
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    // Show the actual per-field diff (old → new), not
                                    // just the field names, so a moderator can see what
                                    // an enrichment/edit really did.
                                    ->state(fn ($record): array => self::formatChanges($record->changes)),
                            ])
                            ->columns(3),
                    ])
                    ->collapsible()
                    // Uses the relation eager-loaded in ViewPlace::resolveRecord() — no extra query.
                    ->visible(fn (Place $record): bool => $record->placeEdits->isNotEmpty()),
                Section::make('Discovery tags')
                    ->description('Materialized from the shared posts/reels (cuisine, vibe, diet, dishes) — these belong to the place. Distinct from the private custom tags users add on their own maps (T-064).')
                    ->columns(2)
                    ->schema([
                        self::tagEntry('Cuisine', TagKind::Cuisine),
                        self::tagEntry('Vibe', TagKind::Vibe),
                        self::tagEntry('Diet', TagKind::Diet),
                        self::tagEntry('Dishes', TagKind::Dish),
                        self::tagEntry('Other', TagKind::Other)
                            ->visible(fn (Place $record): bool => $record->tags->contains('kind', TagKind::Other)),
                    ])
                    ->visible(fn (Place $record): bool => $record->tags->isNotEmpty()),
                Section::make('Location')
                    ->schema([
                        ViewEntry::make('map')
                            ->view('filament.places.map')
                            ->viewData(fn (Place $record): array => ['coordinates' => $record->coordinates()]),
                    ])
                    ->collapsible(),
                Section::make('Possible duplicates')
                    ->description('Nearby places scored by the same query the pipeline dedup uses (trigram/Jaro-Winkler similarity within 75 m).')
                    ->schema([
                        ViewEntry::make('candidates')
                            ->view('filament.places.candidates')
                            ->viewData(fn (Place $record): array => [
                                'candidates' => app(PlaceDedupMatcher::class)->candidatesFor($record),
                            ]),
                    ])
                    ->visible(fn (Place $record): bool => in_array($record->status, [PlaceStatus::Pending, PlaceStatus::Active], true)),
            ]);
    }

    /**
     * Render a place_edits `changes` map ({field: {from, to}}) as readable
     * "field: old → new" lines — the human-facing form of the audit diff.
     *
     * @param  mixed  $changes
     * @return list<string>
     */
    private static function formatChanges($changes): array
    {
        if (! is_array($changes)) {
            return [];
        }

        $lines = [];
        foreach ($changes as $field => $diff) {
            $from = is_array($diff) && array_key_exists('from', $diff) ? self::formatValue($diff['from']) : '—';
            $to = is_array($diff) && array_key_exists('to', $diff) ? self::formatValue($diff['to']) : '—';
            $lines[] = "{$field}: {$from} → {$to}";
        }

        return $lines;
    }

    /** A short, human-readable rendering of an audited value (null/array-aware, capped). */
    private static function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        $text = is_array($value)
            ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $value;

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80).'…' : $text;
    }

    /** One badge list of the place's discovery-tag names for a single kind. */
    private static function tagEntry(string $label, TagKind $kind): TextEntry
    {
        return TextEntry::make("tags_{$kind->value}")
            ->label($label)
            ->badge()
            ->placeholder('—')
            ->state(fn (Place $record): array => $record->tags
                ->where('kind', $kind)
                ->pluck('name')
                ->all());
    }
}
