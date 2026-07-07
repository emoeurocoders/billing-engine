<?php

namespace Omni\BillingEngine\Support;

/**
 * Canonical skip/defer reason strings, so the same condition is logged with the
 * same token no matter where it's detected. Grep production logs by these.
 *
 * `NO_MID` in particular is raised from TWO places for one underlying condition
 * ("no usable/live MID today"): the MidCap guard (early, sticky selection) and
 * the handler's own resolveMid fallback. Both must read the same string — and
 * both get the short no-MID retry via BillingHandler::defer().
 */
final class Reasons
{
    /** Guards passed (or MID cap guard tripped) but no usable/live MID resolved. */
    public const NO_MID = 'no_usable_mid';

    /**
     * Member already has a charge TODAY — skip to avoid a same-day double charge.
     * Transient: the collision is gone tomorrow, so BillingHandler::defer() gives
     * this a short (next-day) retry, NOT a full-cycle park.
     */
    public const SAME_DAY = 'same_day_transaction';
}
