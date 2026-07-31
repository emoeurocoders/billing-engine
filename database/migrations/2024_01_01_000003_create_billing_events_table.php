<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-charge outcome log: billing_events_{vertical}. One row per SKIP or DEAD —
 * the two outcomes the attempt log can't hold, because a guard stops the row
 * before any charge is sent.
 *
 * Why a separate table rather than columns on billing_schedule or a row in
 * billing_attempts:
 *   - The schedule row only ever holds the LATEST state, so a skip is erased the
 *     next time the row is claimed. Reporting needs one row per OCCURRENCE.
 *   - billing_attempts is read by the `already_attempted` guard via exists();
 *     a skip row in there would make every skipped member look already-charged
 *     and permanently stop billing them.
 * Nothing about the existing tables or their indexes changes.
 *
 * `occurred_at` is written through Support\Clock in the BILLING timezone, like
 * next_action_at / claimed_at / the attempt log's `date` — NOT in the app's UTC.
 * Do not add Eloquent-managed timestamps here: save() would stamp them in the
 * app timezone and reintroduce the split-brain this column exists to avoid.
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('billing-engine.events.connection');
    }

    private function table(): string
    {
        return config('billing-engine.events.table', 'billing_events_sports');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        if ($schema->hasTable($this->table())) {
            return;
        }

        $schema->create($this->table(), function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('schedule_id')->nullable(); // link back to the schedule row
            $t->string('member_id', 64);
            $t->string('billing_type', 16);            // rebill|cross1|cross2|convert|settle
            $t->string('card_type', 8)->default('cc');
            $t->string('mid_id', 64)->nullable();      // sticky MID at the time of the decision
            $t->decimal('amount', 10, 2)->nullable();  // revenue deferred/lost by this outcome
            $t->string('outcome', 16);                 // skipped|dead
            $t->string('reason', 64)->nullable();      // guard reason, e.g. no_usable_mid, negative_db:cancel
            $t->char('cycle', 6)->nullable();          // YYYYMM
            $t->dateTime('occurred_at');               // billing-tz wall clock (Clock::now())
            $t->dateTime('created_at')->nullable();

            // Reporting reads by day/hour, and by outcome+day for the split.
            $t->index('occurred_at', 'idx_occurred');
            $t->index(['outcome', 'occurred_at'], 'idx_outcome_occurred');
            $t->index(['billing_type', 'occurred_at'], 'idx_type_occurred');
            $t->index('schedule_id', 'idx_schedule');
            $t->index(['member_id', 'billing_type'], 'idx_member_type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
