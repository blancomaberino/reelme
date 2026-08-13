<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Something else is wrong" — free prose on a suggested edit (T-112).
 *
 * The T-083 form covers name, street, city, phone and website. Everything else
 * a person might want to correct ("this place closed down", "the pin is on the
 * wrong side of the street") had nowhere to go but the *report* flow, whose
 * verbs are take-down and ban — filing a correction there makes it a moderation
 * event against the venue. So the note rides the suggestion, not the report.
 *
 * Two consequences for the schema:
 *
 * - `note` is nullable, and `changes` stays NOT NULL: a note-only proposal
 *   stores an empty diff (`[]` on disk — the column is written through
 *   Eloquent's `array` cast, and PHP encodes an empty array as a list, never as
 *   `{}`), so every renderer AND every query has to survive it.
 * - `status` gains `actioned`. `approve()` means "apply the patch", and a
 *   note-only row has no patch — the moderator fixes the place by hand and
 *   settles the row with a record of what they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_edit_suggestions', function (Blueprint $table): void {
            // Bounded in validation at 2000, matching `reports.details` — the
            // other free-text box on the same screen. `text` rather than a
            // bounded varchar so tightening or loosening that limit stays a
            // validation change and never a table rewrite.
            $table->text('note')->nullable()->after('changes');
        });

        $this->replaceStatusCheck("'pending', 'approved', 'rejected', 'actioned'");
    }

    public function down(): void
    {
        // Back inside the old constraint first: the CHECK is re-added for the
        // whole table, so a single `actioned` row left behind would make the
        // rollback fail on a statement that has nothing to do with the column
        // being dropped. Rejected is the honest resting place — nothing was
        // applied to the place by an `actioned` row either.
        DB::table('place_edit_suggestions')->where('status', 'actioned')->update(['status' => 'rejected']);

        $this->replaceStatusCheck("'pending', 'approved', 'rejected'");

        Schema::table('place_edit_suggestions', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }

    /** Postgres has no ALTER CONSTRAINT for a CHECK — drop and re-add. */
    private function replaceStatusCheck(string $values): void
    {
        DB::statement('ALTER TABLE place_edit_suggestions DROP CONSTRAINT place_edit_suggestions_status_check');
        DB::statement(
            'ALTER TABLE place_edit_suggestions ADD CONSTRAINT place_edit_suggestions_status_check '
            ."CHECK (status IN ({$values}))"
        );
    }
};
