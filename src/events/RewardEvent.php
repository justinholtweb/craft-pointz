<?php

namespace justinholtweb\pointz\events;

use craft\elements\User;
use craft\events\CancelableEvent;
use justinholtweb\pointz\models\Rule;

/**
 * Raised before a rule that is not an order — a review, a birthday, a threshold, a signup or a
 * custom event — hands anything out. Setting `isValid` to false skips this rule for this customer
 * this time; nothing is recorded, so the same trigger can pay out later.
 */
class RewardEvent extends CancelableEvent
{
    public Rule $rule;
    public User $user;
    public int $storeId;

    /**
     * @var string|null What makes this payout unique, e.g. `stars:review:42` or `birthday:2026`.
     *                  A rule pays a customer once per reference.
     */
    public ?string $reference = null;

    /**
     * @var array Whatever the trigger knows: the review, the submission, the caller's payload.
     */
    public array $context = [];
}
