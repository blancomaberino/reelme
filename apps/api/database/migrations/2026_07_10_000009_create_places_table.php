<?php

use App\Enums\PlaceStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deduplicated restaurant entities — the map pins (02 §3.8). First PostGIS table
 * in the schema: `location` is `geography(Point,4326)` with a GIST index, and
 * `normalized_name` carries a trigram GIN index for fuzzy dedup matching. The
 * spatial column and specialised indexes are raw DDL — no ORM/blueprint support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 280);
            // `location geography(Point,4326)` added below via raw DDL.
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2);
            $table->string('google_place_id', 255)->nullable();
            $table->string('cuisine_primary', 64)->nullable();
            $table->smallInteger('price_range')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 2048)->nullable();
            $table->jsonb('opening_hours_json')->nullable();
            $table->string('status', 16)->default(PlaceStatus::Pending->value);
            $table->foreignId('merged_into_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->integer('shares_count')->default(0);
            $table->decimal('avg_extraction_confidence', 4, 3)->nullable();
            $table->string('normalized_name', 255);
            $table->timestampsTz();

            $table->unique('slug');
            $table->index('status');
            $table->index(['country_code', 'city']);
            $table->index('merged_into_place_id');
        });

        Constraints::enumCheck('places', 'status', PlaceStatus::class);

        DB::statement('ALTER TABLE places ADD COLUMN location geography(Point, 4326) NOT NULL');
        DB::statement('ALTER TABLE places ADD CONSTRAINT places_price_range_check CHECK (price_range IS NULL OR price_range BETWEEN 1 AND 4)');

        DB::statement('CREATE INDEX places_location_gist ON places USING GIST (location)');
        DB::statement('CREATE INDEX places_normalized_name_trgm ON places USING GIN (normalized_name gin_trgm_ops)');
        DB::statement('CREATE UNIQUE INDEX places_google_place_id_unique ON places (google_place_id) WHERE google_place_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
