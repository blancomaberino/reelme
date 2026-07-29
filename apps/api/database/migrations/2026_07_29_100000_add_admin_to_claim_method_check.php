<?php

use App\Enums\ClaimMethod;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the influencer_claims.method CHECK to admit `admin` (T-038 follow-up):
 * a moderator can now assign an identity to a user from the Filament panel, and
 * that claim row records `method = admin` for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE influencer_claims DROP CONSTRAINT influencer_claims_method_check');
        // Rebuilds the CHECK from the enum's cases (now including Admin).
        Constraints::enumCheck('influencer_claims', 'method', ClaimMethod::class);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE influencer_claims DROP CONSTRAINT influencer_claims_method_check');
        DB::statement("ALTER TABLE influencer_claims ADD CONSTRAINT influencer_claims_method_check CHECK (method IN ('oauth','bio_code'))");
    }
};
