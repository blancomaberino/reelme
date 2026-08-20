<?php

use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Redemptions\OfferQuotaReconciler;
use App\Services\Redemptions\QuotaDriftReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The reconciliation safety net over `offers.redemptions_count` (T-127, 06 §2.2).
 *
 * The counter is a second copy of a fact the `redemptions` rows already hold,
 * and T-042 → T-127 is the proof that a second copy drifts unwatched. What these
 * tests pin is the property that makes the safety net worth having: **the
 * reconciler's answer is derived from the rows, so it is right even when every
 * writer is wrong** — in both directions, including for an offer that has no
 * redemption rows at all.
 *
 * The command asks a SECOND question the reconciler cannot: whether the rows
 * that hold those slots are still entitled to them. The two audits are tested
 * for INDEPENDENCE rather than only one at a time, because the way a pair of
 * checks fails in production is one of them answering for the other.
 */
function reconciler(): OfferQuotaReconciler
{
    return app(OfferQuotaReconciler::class);
}

/**
 * An offer with all four statuses behind it and a counter left where the real
 * writer put it: `issued` + `redeemed` hold a slot, `expired` and `void` gave
 * theirs back, so the truth is 5 rather than 7.
 */
function offerWithEveryRedemptionStatus(): Offer
{
    $offer = Offer::factory()->active()->create(['quota_total' => 50]);

    // The five slot-holders take their slots through the production writer
    // (`holdingSlot()`), not a hand-set column: this is what makes the healthy
    // case mean something. If OfferQuotaCounter and the reconciler ever
    // disagreed about which statuses hold a slot, this is where it shows.
    Redemption::factory()->count(2)->holdingSlot()->create(['offer_id' => $offer->id]);
    Redemption::factory()->count(3)->redeemed()->holdingSlot()->create(['offer_id' => $offer->id]);
    Redemption::factory()->expired()->create(['offer_id' => $offer->id]);
    Redemption::factory()->void()->create(['offer_id' => $offer->id]);

    return $offer->refresh();
}

/**
 * An `issued` code whose window closed `$hoursAgo`, still holding its slot.
 *
 * Both halves are load-bearing. The ROW is what the command's lapsed-code audit
 * counts; the SLOT is what keeps the COUNTER audit healthy while it does. Seed
 * the row alone and the offer drifts too, both audits fire at once, and a test
 * that meant to pin one of them has proven nothing about either.
 */
function lapsedCode(Offer $offer, int $hoursAgo): Redemption
{
    return Redemption::factory()->holdingSlot()->create([
        'offer_id' => $offer->id,
        'issued_at' => now()->subHours($hoursAgo + 24),
        'expires_at' => now()->subHours($hoursAgo),
    ]);
}

/** Corrupt the cache without touching the rows — the drift this exists to find. */
function forceCounter(Offer $offer, int $value): Offer
{
    $offer->forceFill(['redemptions_count' => $value])->saveQuietly();

    return $offer;
}

/**
 * `$count` offers whose counters all read `$counter` with no redemption row
 * behind any of them — a table full of drift, for the two reports that are
 * about the SIZE of the drift set rather than about any one offer.
 *
 * One venue and one operator for the lot, and a single UPDATE for the
 * corruption. Built the ordinary way — an offer at a time, each dragging in its
 * own place and user, each counter corrupted with its own write — these two
 * tests spend ~220 statements to prove one `array_slice`.
 */
function driftingOffers(int $count, int $counter): void
{
    $place = Place::factory()->create();
    $operator = User::factory()->create();

    $offers = Offer::factory()->count($count)->active()->create([
        'place_id' => $place->id,
        'created_by_user_id' => $operator->id,
    ]);

    DB::table('offers')->whereIn('id', $offers->modelKeys())->update(['redemptions_count' => $counter]);
}

describe('recomputing from the rows', function () {
    it('reports zero drift on a seeded database', function () {
        $offer = offerWithEveryRedemptionStatus();
        // An offer nobody has redeemed: counter 0, rows 0. It must count as
        // CHECKED and not as drift — the LEFT join is the only reason it appears
        // in the aggregate at all.
        Offer::factory()->active()->create();

        expect($offer->redemptions_count)->toBe(5);

        $report = reconciler()->reconcile();

        expect($report->isHealthy())->toBeTrue()
            ->and($report->drifting)->toBe([])
            ->and($report->checked)->toBe(2)
            ->and($report->summary())->toContain('2 offer(s) agree');
    });

    // Both directions from one body, because the direction is the diagnosis: a
    // counter above the rows badges an offer sold out while the restaurant is
    // still paying to run it, below them lets it be redeemed past its own cap.
    it('catches a counter that disagrees with the rows', function (int $counter) {
        $offer = forceCounter(offerWithEveryRedemptionStatus(), $counter);

        $report = reconciler()->reconcile();

        expect($report->isHealthy())->toBeFalse()
            ->and($report->drifting)->toBe([
                ['offer_id' => $offer->id, 'counter' => $counter, 'actual' => 5],
            ]);
    })->with(['below the rows' => 2, 'above the rows' => 9]);

    /*
     * The case an INNER join loses: no redemption rows to join to, so the offer
     * disappears from the aggregate entirely and its invented counter is never
     * questioned. This is also the exact shape a bad backfill or a hand-written
     * UPDATE leaves behind.
     */
    it('catches an offer with no redemption rows at all', function () {
        $offer = forceCounter(Offer::factory()->active()->create(), 7);

        expect(reconciler()->reconcile()->drifting)->toBe([
            ['offer_id' => $offer->id, 'counter' => 7, 'actual' => 0],
        ]);
    });

    it('reports every drifting offer without being confused by the healthy ones', function () {
        $low = forceCounter(offerWithEveryRedemptionStatus(), 1);
        $healthy = offerWithEveryRedemptionStatus();
        $high = forceCounter(offerWithEveryRedemptionStatus(), 8);

        $report = reconciler()->reconcile();

        expect($report->checked)->toBe(3)
            ->and(collect($report->drifting)->pluck('offer_id')->all())
            ->toBe(collect([$low->id, $high->id])->sort()->values()->all())
            ->and($healthy->refresh()->redemptions_count)->toBe(5);
    });
});

describe('the verdict an on-call person reads', function () {
    /*
     * The healthy line is asserted above. This is the other one — the string
     * that goes out at 3am, and the only part of the report most people will
     * ever read. It has to carry BOTH numbers: "3 offers drifted" is a broken
     * write path out of 4 and a rounding error out of 40,000.
     */
    it('says how many offers drifted and out of how many', function () {
        forceCounter(offerWithEveryRedemptionStatus(), 1);
        forceCounter(offerWithEveryRedemptionStatus(), 8);
        Offer::factory()->active()->create();

        $report = reconciler()->reconcile();

        expect($report->isHealthy())->toBeFalse()
            ->and($report->summary())
            ->toBe('OFFER QUOTA DRIFT — 2 of 3 offer(s) disagree with their redemption rows.');
    });

    /*
     * The condition this report exists to catch — a regressed writer — drifts
     * EVERY offer, so the set is largest exactly when it fires. Both carriers of
     * it are bounded: the log record (dropped or truncated by the shipper at
     * precisely that moment) and the console listing (nobody scrolls 40,000
     * lines). The COUNT is what carries the severity, and it stays exact.
     */
    it('caps the sample it hands a log or a console, and says how much it left out', function () {
        $drifting = QuotaDriftReport::SAMPLE_SIZE + 3;
        driftingOffers($drifting, counter: 7);

        $report = reconciler()->reconcile();

        expect($report->drifting)->toHaveCount($drifting)
            ->and($report->sample())->toHaveCount(QuotaDriftReport::SAMPLE_SIZE)
            ->and($report->omitted())->toBe(3)
            // The sample is the FIRST rows of the report, not a re-query: the
            // operator's listing and the alert's sample name the same offers.
            ->and($report->sample())->toBe(array_slice($report->drifting, 0, QuotaDriftReport::SAMPLE_SIZE));

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain("OFFER QUOTA DRIFT — {$drifting} of {$drifting} offer(s) disagree")
            ->expectsOutputToContain('… 3 more.')
            ->assertFailed();
    });
});

describe('the command', function () {
    it('exits zero and says so when every counter agrees with its rows', function () {
        offerWithEveryRedemptionStatus();

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain('Offer quotas healthy')
            ->assertSuccessful();
    });

    it('exits non-zero and prints the offer, the counter and the truth', function () {
        $offer = forceCounter(offerWithEveryRedemptionStatus(), 2);

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain("offer {$offer->id}: counter 2, rows say 5")
            ->assertFailed();

        // Report-only: a plain run must not have touched anything.
        expect($offer->refresh()->redemptions_count)->toBe(2);
    });

    /*
     * The seam the whole scheduled command exists for. Nobody watches stdout of
     * a cron job — `routes/console.php` schedules this precisely so the LOG
     * record reaches an alert, and until now the only thing asserted was the
     * output nobody reads. A run that printed perfectly and logged nothing would
     * have been green.
     *
     * `source` is the key that matters most, for the reason
     * `OfferQuotaCounter::release()` gives: two detectors, one message, two key
     * sets, and one alert rule that has to match both.
     */
    it('logs the drift for the alert, with a bounded sample and its own source', function () {
        $drifting = QuotaDriftReport::SAMPLE_SIZE + 1;
        driftingOffers($drifting, counter: 4);

        Log::spy();

        $this->artisan('reelmap:offers:reconcile-quotas')->assertFailed();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'offer.quota_counter_drift'
                && $context['source'] === 'reconcile'
                && $context['checked'] === $drifting
                && $context['drifting'] === $drifting
                && $context['fix'] === false
                // Bounded, even though `drifting` above reports the true total.
                && count($context['sample']) === QuotaDriftReport::SAMPLE_SIZE)
            ->once();
    });

    it('repairs both directions with --fix and then reports clean', function () {
        $low = forceCounter(offerWithEveryRedemptionStatus(), 1);
        $high = forceCounter(offerWithEveryRedemptionStatus(), 8);
        $untouched = Offer::factory()->active()->create();

        $this->artisan('reelmap:offers:reconcile-quotas', ['--fix' => true])
            ->expectsOutputToContain('Repaired 2 of the 2 offer(s) reported above.')
            ->assertSuccessful();

        expect($low->refresh()->redemptions_count)->toBe(5)
            ->and($high->refresh()->redemptions_count)->toBe(5);

        // The proof that the repair was complete, not merely attempted: the
        // same read the scheduler makes now passes.
        $this->artisan('reelmap:offers:reconcile-quotas')->assertSuccessful();
        expect(reconciler()->reconcile()->isHealthy())->toBeTrue()
            ->and($untouched->refresh()->redemptions_count)->toBe(0);
    });

    /*
     * The ORDER is the point, and it is asserted the only way order can be: by
     * reading the table from inside the log call itself. Logged before the
     * write, the record says "repaired 1" over a row that had not moved yet, and
     * a crash in between leaves a log line claiming a repair that never
     * happened. A `withArgs` assertion after the fact cannot tell the two apart.
     */
    it('records the repair in the log, after the write it is claiming', function () {
        $offer = forceCounter(offerWithEveryRedemptionStatus(), 1);

        $storedWhenLogged = null;
        Log::partialMock()
            ->shouldReceive('warning')
            ->andReturnUsing(function (string $message, array $context) use (&$storedWhenLogged, $offer): void {
                if ($message === 'offer.quota_counter_repaired') {
                    $storedWhenLogged = [
                        'context' => $context,
                        'counter' => DB::table('offers')->where('id', $offer->id)->value('redemptions_count'),
                    ];
                }
            });

        $this->artisan('reelmap:offers:reconcile-quotas', ['--fix' => true])->assertSuccessful();

        expect($storedWhenLogged)->not->toBeNull()
            // Already 5 by the time the line was written — the log is a record
            // of a completed write, not an intention to make one.
            ->and($storedWhenLogged['counter'])->toBe(5)
            ->and($storedWhenLogged['context'])->toMatchArray([
                'source' => 'reconcile',
                'repaired' => 1,
                'reported' => 1,
                'checked' => 1,
            ]);
    });

    /*
     * A repaired counter must not make the offer look edited. `updated_at` is
     * the operator's own signal about their own content, and a nightly
     * bookkeeping correction that bumps it re-sorts their list and invalidates
     * caches for a row whose content never changed.
     */
    it('does not touch updated_at when it repairs a counter', function () {
        $offer = forceCounter(offerWithEveryRedemptionStatus(), 1);
        $before = $offer->updated_at;

        $this->travel(1)->hours();

        $this->artisan('reelmap:offers:reconcile-quotas', ['--fix' => true])->assertSuccessful();

        expect($offer->refresh()->redemptions_count)->toBe(5)
            ->and($offer->updated_at->equalTo($before))->toBeTrue();
    });
});

describe('the repair', function () {
    /*
     * Pest holds ONE connection, so the race this lock exists to lose cannot be
     * run here: there is no second session to commit a claim while the UPDATE is
     * in flight. What CAN be pinned is the mechanism — that the rows are taken
     * `FOR UPDATE` before the recompute writes over them, and that the set taken
     * is the drifting set rather than the whole table.
     *
     * Why that lock is not defensive tidiness (EvalPlanQual replaying a stale
     * subplan over a winning claim) is written out at
     * `OfferQuotaReconciler::repair()`.
     */
    it('locks the offers it is about to rewrite, and only those', function () {
        $low = forceCounter(offerWithEveryRedemptionStatus(), 1);
        $high = forceCounter(offerWithEveryRedemptionStatus(), 8);
        $healthy = offerWithEveryRedemptionStatus();

        $report = reconciler()->reconcile();

        $locks = collect();
        DB::listen(function ($query) use ($locks): void {
            if (str_contains($query->sql, 'from "offers"') && str_contains($query->sql, 'for update')) {
                $locks->push($query->bindings);
            }
        });

        expect(reconciler()->repair($report))->toBe(2);

        // Scoped to the drifting rows, not the table: a repair that locked every
        // offer would freeze issuance across the whole platform for its
        // duration, and `ORDER BY id` is what makes two concurrent repairs queue
        // instead of deadlocking.
        expect($locks)->toHaveCount(1)
            ->and($locks->first())->toBe([$low->id, $high->id])
            ->and($locks->first())->not->toContain($healthy->id);
    });

    /*
     * `repair()` takes the report rather than re-deriving its own drift set, so
     * the offers the operator was shown and the rows this writes are the same
     * rows. Handing it a SUBSET is what separates the two: an implementation
     * that ran its own aggregate would repair all three here and still report
     * "2", leaving "Repaired 2 of the 2 offer(s) reported above" sitting over a
     * write that touched an offer nobody listed.
     */
    it('writes exactly the offers the report named, and nothing outside it', function () {
        $listed = forceCounter(offerWithEveryRedemptionStatus(), 1);
        $alsoListed = forceCounter(offerWithEveryRedemptionStatus(), 8);
        $omitted = forceCounter(offerWithEveryRedemptionStatus(), 2);

        $full = reconciler()->reconcile();
        expect($full->drifting)->toHaveCount(3);

        $partial = new QuotaDriftReport(
            checked: $full->checked,
            drifting: array_values(array_filter(
                $full->drifting,
                fn (array $row): bool => $row['offer_id'] !== $omitted->id,
            )),
        );

        expect(reconciler()->repair($partial))->toBe(2);

        expect($listed->refresh()->redemptions_count)->toBe(5)
            ->and($alsoListed->refresh()->redemptions_count)->toBe(5)
            // Untouched, and therefore still drifting: the next run finds it.
            ->and($omitted->refresh()->redemptions_count)->toBe(2)
            ->and(reconciler()->reconcile()->drifting)->toBe([
                ['offer_id' => $omitted->id, 'counter' => 2, 'actual' => 5],
            ]);
    });

    /*
     * The healthy night, which is nearly every night. An empty report must cost
     * nothing at all — no lock, no UPDATE, not even an empty `IN ()` that
     * Postgres would reject. The `return 0` guard is the whole of it, and
     * without it this is a table-wide statement run for no reason.
     */
    it('takes no lock and writes nothing when there is nothing to repair', function () {
        $offer = offerWithEveryRedemptionStatus();
        $report = reconciler()->reconcile();
        expect($report->isHealthy())->toBeTrue();

        $statements = collect();
        DB::listen(function ($query) use ($statements): void {
            $statements->push($query->sql);
        });

        expect(reconciler()->repair($report))->toBe(0)
            ->and($statements)->toBeEmpty()
            ->and($offer->refresh()->redemptions_count)->toBe(5);
    });
});

describe('the second audit: slots held by codes that already lapsed', function () {
    /*
     * The condition a counter audit is structurally incapable of seeing: the
     * hourly sweep stopping, which freezes an offer's cap while the counter and
     * the rows go on agreeing perfectly — both of them wrong about the world.
     * What that costs the venue is written out at
     * `ReconcileOfferQuotas::auditLapsedCodes()`.
     */
    it('says nothing when no code has lapsed', function () {
        offerWithEveryRedemptionStatus();

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain('Offer quotas healthy')
            ->doesntExpectOutputToContain('lapsed')
            ->assertSuccessful();
    });

    /*
     * The sweep runs hourly, so at any moment up to an hour of legitimately
     * lapsed codes are still holding their slots. Failing on a non-zero count
     * would fire on very nearly every run, and a check that always fails is a
     * check nobody reads — which is the same as not having it.
     */
    it('reports codes lapsed since the last sweep without failing over them', function () {
        $offer = Offer::factory()->active()->create(['quota_total' => 50]);
        lapsedCode($offer, hoursAgo: 1);
        lapsedCode($offer, hoursAgo: 1);

        $this->artisan('reelmap:offers:reconcile-quotas')
            // One expectation, one line: `expectsOutputToContain` consumes a
            // distinct write per call, so two substrings of the SAME line only
            // ever match once.
            ->expectsOutputToContain('2 issued code(s) lapsed since the last sweep and still hold a slot — expected; reelmap:redemptions:expire returns them within the hour.')
            ->assertSuccessful();
    });

    it('fails once a code has held its slot past the stall threshold', function () {
        $offer = Offer::factory()->active()->create(['quota_total' => 50]);
        $recent = lapsedCode($offer, hoursAgo: 1);
        lapsedCode($offer, hoursAgo: 5);

        Log::spy();

        $this->artisan('reelmap:offers:reconcile-quotas')
            // Both numbers: everything still holding a slot, and the subset that
            // proves the sweep is not merely behind but stopped.
            ->expectsOutputToContain('OFFER QUOTA HELD BY LAPSED CODES — 2 issued code(s) are past expires_at and still hold a slot, 1 of them by over 2h.')
            ->expectsOutputToContain('`--fix` cannot repair this')
            ->assertFailed();

        expect($recent->fresh()->status->value)->toBe('issued');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'offer.quota_slots_held_by_lapsed_codes'
                && $context['held'] === 2
                && $context['stalled'] === 1
                && $context['stall_hours'] === 2)
            ->once();
    });

    /*
     * `--fix` repairs drift by recomputing the counter from the rows. This
     * condition IS the rows, so the recompute reproduces it exactly — which is
     * why the run still has to fail. A `--fix` that exited 0 here would be a
     * command reporting success for having rewritten the wrong number to the
     * same wrong number.
     */
    it('still fails under --fix, because a recompute cannot return a held slot', function () {
        $offer = Offer::factory()->active()->create(['quota_total' => 50]);
        lapsedCode($offer, hoursAgo: 5);

        $this->artisan('reelmap:offers:reconcile-quotas', ['--fix' => true])
            ->expectsOutputToContain('OFFER QUOTA HELD BY LAPSED CODES')
            ->assertFailed();

        // The counter was right all along, and still is.
        expect($offer->refresh()->redemptions_count)->toBe(1);
    });

    /*
     * The independence the two audits are separate methods for. A drifting
     * counter must not stand in for a stalled sweep, and vice versa — folded
     * into one verdict, either condition would mask the other's absence and the
     * exit code would stop meaning anything specific.
     */
    it('reports a stalled sweep even while every counter is perfect', function () {
        $offer = Offer::factory()->active()->create(['quota_total' => 50]);
        lapsedCode($offer, hoursAgo: 5);

        $this->artisan('reelmap:offers:reconcile-quotas')
            // Audit one passes and SAYS so; audit two is what fails the run.
            ->expectsOutputToContain('Offer quotas healthy')
            ->expectsOutputToContain('OFFER QUOTA HELD BY LAPSED CODES')
            ->assertFailed();

        expect(reconciler()->reconcile()->isHealthy())->toBeTrue();
    });

    /*
     * Both conditions at once, report-only — the shape a short-circuit hides.
     *
     * `$countersTrue && $this->auditLapsedCodes()` reads as an equivalent
     * refactor and is not: with drift already found, PHP never evaluates the
     * right-hand side, so the stalled sweep is never counted and never printed.
     * The exit code is 1 either way, which is why the OUTPUT is what is asserted
     * here — an operator who fixes the drift the command told them about would
     * otherwise be told nothing about the venue whose cap is frozen, and would
     * find out from the restaurant.
     */
    it('reports a stalled sweep alongside drift, not instead of it', function () {
        $drifting = forceCounter(offerWithEveryRedemptionStatus(), 2);
        $stalled = Offer::factory()->active()->create(['quota_total' => 50]);
        lapsedCode($stalled, hoursAgo: 5);

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain("offer {$drifting->id}: counter 2, rows say 5")
            ->expectsOutputToContain('OFFER QUOTA HELD BY LAPSED CODES')
            ->assertFailed();
    });

    it('reports drift even while the sweep is keeping up', function () {
        $offer = forceCounter(offerWithEveryRedemptionStatus(), 2);

        $this->artisan('reelmap:offers:reconcile-quotas')
            ->expectsOutputToContain("offer {$offer->id}: counter 2, rows say 5")
            ->doesntExpectOutputToContain('OFFER QUOTA HELD BY LAPSED CODES')
            ->assertFailed();
    });

    /*
     * Both at once, with `--fix`: the repair does what it can and the run STILL
     * fails, on the strength of the condition it cannot repair. This is the one
     * assertion that a single folded verdict could not survive — a command that
     * returned "did I fix everything I found?" would exit 0 here, and the
     * stalled sweep would go unreported on the one night it mattered.
     */
    it('repairs the drift it can and still fails on the sweep it cannot', function () {
        $drifting = forceCounter(offerWithEveryRedemptionStatus(), 2);
        $stalled = Offer::factory()->active()->create(['quota_total' => 50]);
        lapsedCode($stalled, hoursAgo: 5);

        $this->artisan('reelmap:offers:reconcile-quotas', ['--fix' => true])
            ->expectsOutputToContain('Repaired 1 of the 1 offer(s) reported above.')
            ->expectsOutputToContain('OFFER QUOTA HELD BY LAPSED CODES')
            ->assertFailed();

        expect($drifting->refresh()->redemptions_count)->toBe(5)
            ->and(reconciler()->reconcile()->isHealthy())->toBeTrue();
    });
});
