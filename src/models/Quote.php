<?php

namespace justinholtweb\pointz\models;

use craft\base\Model;

/**
 * What a cart's redemption would come to right now.
 *
 * The adjuster turns a quote into adjustments, the site controller returns one so a checkout form
 * can say why it clamped a customer's request, and the Twig API hands one to templates. One
 * calculation, three audiences.
 */
class Quote extends Model
{
    /** The figure redemption is measured against, per the `redeemableBase` setting. */
    public float $base = 0;

    /** The most money points may take off this order. */
    public float $cap = 0;

    /** What the customer asked for. */
    public float $requestedPoints = 0;
    public float $requestedCredit = 0;

    /** What they will actually get, after every clamp. */
    public float $points = 0;
    public float $pointsValue = 0;
    public float $credit = 0;

    /** The most points this order could take, given the balance and the caps. */
    public float $maxPoints = 0;
    public float $maxCredit = 0;

    public float $pointsBalance = 0;
    public float $creditBalance = 0;

    /** @var string[] Why the request was clamped, in the customer's language. */
    public array $notices = [];

    public function getTotalDiscount(): float
    {
        return round($this->pointsValue + $this->credit, 5);
    }

    public function getIsEmpty(): bool
    {
        return $this->points <= 0 && $this->credit <= 0;
    }

    /**
     * Whether the customer got everything they asked for.
     */
    public function getWasClamped(): bool
    {
        return $this->points < $this->requestedPoints || $this->credit < $this->requestedCredit;
    }
}
