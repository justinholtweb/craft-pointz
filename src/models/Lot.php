<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * A parcel of earned value with its own expiry date.
 *
 * Balances are the sum of the remaining figures across a customer's available lots. Spending
 * consumes lots soonest-expiry-first, which is the only ordering that does not silently throw
 * value away.
 */
class Lot extends Model
{
    /** Earned, but waiting on the order to reach the configured status or clear its hold. */
    public const STATUS_PENDING = 'pending';

    /** Spendable. */
    public const STATUS_AVAILABLE = 'available';

    /** Past its expiry date, swept by `pointz/expire`. */
    public const STATUS_EXPIRED = 'expired';

    /** Taken back — a refund reversed the order that earned it. */
    public const STATUS_REVOKED = 'revoked';

    public ?int $id = null;
    public ?int $transactionId = null;
    public ?int $storeId = null;
    public ?int $userId = null;
    public string $currency = Rule::CURRENCY_POINTS;
    public float $amount = 0;
    public float $remaining = 0;
    public string $status = self::STATUS_AVAILABLE;
    public ?int $orderId = null;
    public ?int $ruleId = null;
    public ?DateTime $dateAvailable = null;
    public ?DateTime $dateExpires = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateAvailable', 'dateExpires']);
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => Craft::t('pointz', 'Pending'),
            self::STATUS_AVAILABLE => Craft::t('pointz', 'Available'),
            self::STATUS_EXPIRED => Craft::t('pointz', 'Expired'),
            self::STATUS_REVOKED => Craft::t('pointz', 'Revoked'),
        ];
    }

    public function getStatusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    /**
     * How much of the lot has already been spent.
     */
    public function getSpent(): float
    {
        return round($this->amount - $this->remaining, 5);
    }

    public function getIsSpendable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && $this->remaining > 0;
    }
}
