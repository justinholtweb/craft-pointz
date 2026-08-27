<?php

namespace justinholtweb\pointz\events;

use craft\commerce\elements\Order;
use craft\elements\User;
use justinholtweb\pointz\models\Award;
use yii\base\Event;

/**
 * Raised once per order after the rules have produced an award and before any of it is written.
 *
 * This is the extension point for anything the rule engine cannot express — a partner tier, a
 * birthday multiplier, a rule that reads a custom field. Change `$award`, or set `$award` to a
 * zero award to skip the order entirely.
 */
class AwardEvent extends Event
{
    public Award $award;
    public ?Order $order = null;
    public ?User $user = null;

    /**
     * @var bool Whether this is a preview rather than a real accrual. A preview must not have
     *           side effects, so a handler that writes anything should check this.
     */
    public bool $isPreview = false;
}
