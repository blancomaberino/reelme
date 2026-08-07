<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User-generated moderation flags (T-049, 02 §3.17).
 *
 * Apple Guideline 1.2 and Google's UGC policy both require a report mechanism
 * before a user-generated-content app can ship, so this is a launch blocker
 * rather than a nicety — and the reviewer checks that it is reachable, not that
 * it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();

            // Morph ALIAS, never a FQCN — the values here are `place`, `share`,
            // `user`, `source_post`, `offer` (AppServiceProvider's morph map).
            // Storing class names would tie the database to a PHP namespace and
            // break the moment anything moves.
            $table->string('reportable_type', 32);
            $table->unsignedBigInteger('reportable_id');

            $table->string('reason', 24);
            $table->text('details')->nullable();
            $table->string('status', 16)->default(ReportStatus::Open->value);

            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index('status');

            // One report per person per reason per target. Without it, a single
            // motivated user can manufacture a pile of reports that looks like
            // consensus — and the queue sorts by count.
            $table->unique(['reporter_user_id', 'reportable_type', 'reportable_id', 'reason']);
        });

        // CHECK constraints rather than trusting the enum casts alone: the
        // admin panel, a console command and a future backfill all write this
        // table, and only the database is in every one of those paths.
        $this->check('reports_reason_check', 'reason', array_column(ReportReason::cases(), 'value'));
        $this->check('reports_status_check', 'status', array_column(ReportStatus::cases(), 'value'));
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }

    /**
     * @param  list<string>  $values
     */
    private function check(string $name, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));

        DB::statement("ALTER TABLE reports ADD CONSTRAINT {$name} CHECK ({$column} IN ({$list}))");
    }
};
