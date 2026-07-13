<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional profile personalization the user edits themselves: date of birth
 * (age is derived, never stored stale) and free-text interest lists (topics,
 * favorite foods) that will later personalize discovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('birthdate')->nullable()->after('bio');
            $table->jsonb('favorite_topics')->nullable()->after('birthdate');
            $table->jsonb('favorite_foods')->nullable()->after('favorite_topics');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['birthdate', 'favorite_topics', 'favorite_foods']);
        });
    }
};
