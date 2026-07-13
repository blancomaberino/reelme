<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership rows for a place_list (T-062): one place per list (unique),
 * optional per-item note, explicit ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->string('note', 280)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['place_list_id', 'place_id']);
            $table->index('place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_list_items');
    }
};
