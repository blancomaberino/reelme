<?php

use App\Enums\Platform;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User-linked platform accounts (02-data-model §3.2, T-015). Their OAuth tokens
 * let the ingestion pipeline fetch private posts the sharer is authorized to see
 * (the "Graph API with linked user token" chain step, 01 §5). Tokens are stored
 * encrypted at rest via the model's `encrypted` casts — never plaintext here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // citext is enabled by the 0000_ extensions migration (guaranteed first);
        // re-assert only the one this migration's ALTER depends on.
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // + CHECK against Platform enum below
            $table->string('external_user_id', 255);
            $table->string('handle'); // → citext below (case-insensitive @handle)
            $table->text('access_token')->nullable();  // encrypted cast (model)
            $table->text('refresh_token')->nullable(); // encrypted cast (model)
            $table->timestampTz('token_expires_at')->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index('handle');
        });

        DB::statement('ALTER TABLE platform_accounts ALTER COLUMN handle TYPE citext');
        // One row per (platform, external_user_id): the same Instagram identity
        // can't be linked twice, and never to two Reelmap users at once.
        DB::statement('ALTER TABLE platform_accounts ADD CONSTRAINT platform_accounts_platform_external_user_id_unique UNIQUE (platform, external_user_id)');
        // One account per platform per user (updateOrCreate target on re-link).
        DB::statement('ALTER TABLE platform_accounts ADD CONSTRAINT platform_accounts_user_id_platform_unique UNIQUE (user_id, platform)');
        Constraints::enumCheck('platform_accounts', 'platform', Platform::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
