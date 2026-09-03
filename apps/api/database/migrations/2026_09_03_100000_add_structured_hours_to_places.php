<?php

use App\Support\OpeningSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured opening hours and a timezone, so "open now" can be a FACT rather
 * than a guess (T-155).
 *
 * `opening_hours_json` stays exactly as it is: the flat `string[]` of
 * human-readable lines the client renders verbatim (T-128). It is not touched,
 * not reinterpreted, and not turned into a union — that union is precisely the
 * defect T-128 removed. These are two NEW nullable columns beside it.
 *
 * Why both columns, and why either being null must void the cue:
 *
 * - `opening_hours_periods_json` holds Google's `periods[]` normalized to a
 *   pinned shape ({@see OpeningSchedule}). The data was already
 *   being paid for and thrown away — `BUSINESS_FIELDS` requests `opening_hours`
 *   and Google returns `{open_now, periods, weekday_text}`, of which the
 *   geocoder kept only `weekday_text`.
 * - `timezone` holds an IANA zone id ("America/Montevideo"), never a fixed UTC
 *   offset. An offset is wrong for half the year anywhere that observes DST, and
 *   a status cue that is wrong half the year is worse than no cue: it sends
 *   someone away from a restaurant that is open and wanted their business.
 *
 * Both are nullable and BOTH are required to compute a status. A place with
 * periods but no zone, or a zone but no periods, shows the hours lines and no
 * cue at all — the T-128 rule, now enforced by the shape of the data rather than
 * by a convention someone has to remember.
 *
 * No backfill. Provenance for hours already on a row is unknown, and there is
 * nothing to derive structured periods FROM: the stored value is prose whose
 * weekday ordering is locale-dependent and which carries no timezone. Existing
 * rows gain a cue when they are next enriched, and show none until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->jsonb('opening_hours_periods_json')->nullable()->after('opening_hours_json');
            // IANA zone ids are short ("America/Argentina/ComodRivadavia" is the
            // longest in the database at 32); 64 leaves room without inviting a
            // caller to store something that is not a zone id.
            $table->string('timezone', 64)->nullable()->after('opening_hours_periods_json');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropColumn(['opening_hours_periods_json', 'timezone']);
        });
    }
};
