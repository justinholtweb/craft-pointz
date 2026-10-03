<?php

namespace justinholtweb\pointz\events;

use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\models\Rule;

/**
 * Raised around a coupon's life: after it is issued, and before its expiry reminder goes out.
 *
 * Setting `isValid` to false on the reminder event stops Pointz sending its own email — the way
 * to send the reminder through a different mailer, or not at all, without losing the record that
 * the reminder was due.
 */
class CouponEvent extends CancelableEvent
{
    public Coupon $coupon;
    public ?User $user = null;
    public ?Rule $rule = null;
}
