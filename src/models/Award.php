<?php

namespace justinholtweb\pointz\models;

use craft\base\Model;

/**
 * What a set of rules decided an order — or a hypothetical one — is worth.
 *
 * An award is a plan, not a movement. `services\Earning` computes one for a real order and for a
 * preview through exactly the same code, which is the only way a "you'll earn 240 points" message
 * on a product page can be trusted.
 */
class Award extends Model
{
    /** @var AwardLine[] */
    public array $lines = [];

    /** @var string[] Reasons an award came out smaller, or empty, than the shop expected. */
    public array $notices = [];

    public function addLine(AwardLine $line): void
    {
        $this->lines[] = $line;
    }

    public function getPoints(): float
    {
        return $this->totalFor(Rule::CURRENCY_POINTS);
    }

    public function getCredit(): float
    {
        return $this->totalFor(Rule::CURRENCY_CREDIT);
    }

    public function totalFor(string $currency): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            if ($line->currency === $currency) {
                $total += $line->amount;
            }
        }

        return round($total, 5);
    }

    /**
     * @return AwardLine[]
     */
    public function linesFor(string $currency): array
    {
        return array_values(array_filter($this->lines, static fn(AwardLine $line) => $line->currency === $currency));
    }

    public function getIsEmpty(): bool
    {
        return $this->getPoints() <= 0 && $this->getCredit() <= 0;
    }

    /**
     * Drops every line, leaving the notices. What a handler calls to veto an order.
     */
    public function clear(): void
    {
        $this->lines = [];
    }
}
