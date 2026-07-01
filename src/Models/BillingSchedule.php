<?php

namespace Omni\BillingEngine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Source of truth for due billing actions. The table name + connection are
 * resolved from config at construction so the same model serves every vertical
 * (billing_schedule_sports, billing_schedule_pdf, ...).
 */
class BillingSchedule extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_DONE    = 'done';
    public const STATUS_DEAD    = 'dead';
    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected $casts = [
        'amount'         => 'decimal:2',
        'attempts'       => 'integer',
        'next_action_at' => 'datetime',
        'claimed_at'     => 'datetime',
        'meta'           => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('billing-engine.schedule.connection'));
        $this->setTable(config('billing-engine.schedule.table', 'billing_schedule_sports'));
        parent::__construct($attributes);
    }
}
