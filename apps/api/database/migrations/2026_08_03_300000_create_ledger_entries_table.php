<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger (T-044, 02 §3.15, 06 §4.2).
 *
 * **Append-only.** No `updated_at`, and the model refuses updates and deletes —
 * a correction is a reversing entry, never an edit. That is not fastidiousness:
 * an editable ledger cannot answer "what did we believe on the 3rd", which is
 * the only question that matters in a dispute or an audit.
 *
 * **Balances are never stored.** Every figure in the product — a restaurant's
 * outstanding invoice, an influencer's payable, the platform's margin — is
 * derived by summing entries. A cached balance is a second source of truth for
 * money, and the two WILL diverge.
 *
 * Two constraints carry that design:
 *
 * 1. **`unique(idempotency_key)`** — the posting path is inside the redemption
 *    verify transaction, which is retried by clients and (eventually) by
 *    queues. The key is what makes "post the fee for redemption 123" safe to
 *    attempt twice: the second insert loses to the index and the service reads
 *    the first transaction back instead of writing a second fee.
 * 2. **`CHECK (amount > 0)`** — sign lives in `direction`, never in the number.
 *    A negative amount would let one row be simultaneously a debit and a credit
 *    depending on who read it, and would silently satisfy the balance check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            // Groups the balanced set. Not a FK to anything — a transaction is
            // the rows that share this uuid, and it has no separate identity.
            $table->uuid('transaction_uuid');
            $table->string('account', 32);
            $table->string('direction', 8);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            // Morph to what caused the posting (a redemption, a payout). Kept
            // nullable: an opening balance or a manual correction has no cause
            // beyond its memo.
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            /*
             * The subledger owner. On `influencer_earnings` this is WHOSE
             * earnings — and a NULL means escrow: money owed to an influencer
             * identity nobody has claimed yet (06 §5.3). Deliberately NOT a
             * cascading FK: deleting a user must never delete the record that
             * we owe them money.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->string('memo', 255)->nullable();
            // created_at only — 02 §3.15 is explicit that there is no
            // updated_at, because there is no update.
            $table->timestampTz('created_at')->useCurrent();

            $table->index('transaction_uuid');
            // The balance query: "this account, this party, this currency".
            $table->index(['account', 'user_id', 'currency']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive_check CHECK (amount > 0)');

        /*
         * Append-only in the DATABASE, not merely in the model.
         *
         * The model guard stops application code; this stops everything else —
         * a tinker session, a migration, an admin with psql. For a table whose
         * entire value is that history cannot be rewritten, "we promise not to"
         * is not the same guarantee as "the database refuses".
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reelmap_ledger_entries_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only: correct with a reversing entry (02 §3.15)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION reelmap_ledger_entries_append_only();
        SQL);
    }

    public function down(): void
    {
        // The trigger goes with the table; the function is shared by nothing
        // else, so drop it explicitly.
        Schema::dropIfExists('ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS reelmap_ledger_entries_append_only()');
    }
};
