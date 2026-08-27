<?php

namespace justinholtweb\pointz\models;

use craft\base\Model;

/**
 * One rule's contribution to an award — kept separate from the total so the order panel, the
 * preview and the ledger note can all say *why* a customer got what they got.
 */
class AwardLine extends Model
{
    public ?int $ruleId = null;
    public string $ruleName = '';
    public string $currency = Rule::CURRENCY_POINTS;

    /** What the rule awarded, after its multiplier, rounding and caps. */
    public float $amount = 0;

    /** The monetary figure the rate was applied to. Zero for a fixed award. */
    public float $basis = 0;

    /** Set when the rule was per-line-item. */
    public ?int $lineItemId = null;
    public ?string $lineItemDescription = null;

    /** Days until this line's value expires, or null for never. */
    public ?int $expireAfterDays = null;

    /** Set when a cap trimmed the award, so the CP can say so instead of just showing a smaller number. */
    public ?string $note = null;
}
