<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the per-vertical schedule table. The name + connection come from
 * config so each vertical gets its own table from the same migration:
 *   php artisan migrate   (with BILLING_VERTICAL / billing-engine.schedule.*)
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('billing-engine.schedule.connection');
    }

    private function table(): string
    {
        return config('billing-engine.schedule.table', 'billing_schedule_sports');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        if ($schema->hasTable($this->table())) {
            return;
        }

        $schema->create($this->table(), function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('member_id', 64);
            $t->enum('billing_type', ['rebill', 'cross1', 'cross2', 'convert', 'settle']);
            $t->enum('card_type', ['cc', 'pp'])->default('cc');
            $t->string('source_tr_id', 64)->nullable();
            $t->string('mid_id', 64)->nullable();          // sticky MID; null for rotation
            $t->decimal('amount', 10, 2);
            $t->dateTime('next_action_at');                // explicit due date
            $t->enum('status', ['pending', 'claimed', 'done', 'dead', 'skipped'])->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('step')->default(0); // step-down rung (0 = full price)
            $t->dateTime('claimed_at')->nullable();
            $t->string('last_decline_code', 16)->nullable();
            $t->char('cycle', 6);                          // YYYYMM
            $t->string('idempotency_key', 128);            // {member}:{type}:{cycle}
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->unique('idempotency_key', 'uq_idem');
            $t->index(['status', 'next_action_at'], 'idx_due');
            $t->index(['member_id', 'billing_type'], 'idx_member');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
