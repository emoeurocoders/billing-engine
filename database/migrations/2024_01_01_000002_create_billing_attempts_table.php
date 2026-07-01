<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine-owned, unified attempt log: billing_attempts_{vertical}. One table per
 * vertical holding EVERY billing_type (rebill/cross1/cross2/convert/settle),
 * replacing the ~50 legacy per-type tables (rebill_, conversion_, settle_).
 *
 * During migration the engine dual-writes (this table + the legacy table). Once
 * this table has enough history, flip billing-engine.log.read_from to 'unified'
 * and retire the legacy tables.
 *
 * Columns mirror the legacy shape (uid/result/decline_code/amount/attempt/retry/
 * tr_id/processor) so parity checks and historical reporting port cleanly, plus
 * billing_type / cross_target / mid_id / schedule_id for the unified model.
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('billing-engine.attempts.connection');
    }

    private function table(): string
    {
        return config('billing-engine.attempts.table', 'billing_attempts_sports');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        if ($schema->hasTable($this->table())) {
            return;
        }

        $schema->create($this->table(), function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('member_id', 64);                 // legacy: uid
            $t->string('billing_type', 16);              // rebill|cross1|cross2|convert|settle
            $t->string('cross_target', 32)->nullable();  // cross product (games/vod/fit/...) when applicable
            $t->string('card_type', 8)->default('cc');
            $t->string('mid_id', 64)->nullable();
            $t->decimal('amount', 10, 2)->nullable();
            $t->boolean('result');                       // 1 = approved
            $t->string('decline_code', 16)->nullable();
            $t->string('transaction_id', 64)->nullable();// legacy: tr_id
            $t->string('processor', 24)->nullable();     // nmi|inovio
            $t->unsignedSmallInteger('attempt_no')->default(1); // legacy: attempt
            $t->string('retry', 16)->nullable();         // legacy: retry (completed|pending)
            $t->unsignedBigInteger('schedule_id')->nullable(); // link back to billing_schedule
            $t->char('cycle', 6)->nullable();            // YYYYMM
            $t->dateTime('date');                        // attempt timestamp (legacy: date)
            $t->timestamps();

            $t->index(['member_id', 'billing_type', 'date'], 'idx_member_type_date');
            $t->index(['result', 'billing_type'], 'idx_result_type');
            $t->index('date', 'idx_date');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
