<?php

namespace justinholtweb\pointz\events;

use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\models\Rule;

/**
 * Raised around a coupon's life: after it is issued, and before each of its reminders goes out.
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

    /**
     * On the reminder event, which reminder this is: `Coupon::REMIND_EXPIRY`, or
     * `Coupon::REMIND_ISSUED` for one counted from the day the code was issued.
     */
    public ?string $reminder = null;
}
