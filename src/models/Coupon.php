<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use DateTime;
use justinholtweb\pointz\Plugin;

/**
 * A coupon code Pointz issued to one customer.
 *
 * The code itself lives in Commerce, on the discount the rule owns, with a single use. This row is
 * what Commerce cannot say: who the code belongs to, when it stops working, and whether anybody
 * has been reminded. A coupon is never a balance and never touches the ledger — when a threshold
 * rule *buys* one with points, the spend is an ordinary ledger debit and `transactionId` points
 * at it.
 */
class Coupon extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public ?int $id = null;
    public ?int $storeId = null;
    public ?int $userId = null;
    public ?int $ruleId = null;
    public ?int $discountId = null;
    public ?int $couponId = null;
    public ?int $transactionId = null;
    public ?int $orderId = null;
    public string $code = '';
    public string $type = Rule::COUPON_PERCENT;
    public float $amount = 0;
    public string $status = self::STATUS_ACTIVE;
    public ?string $reference = null;
    public ?DateTime $dateExpires = null;
    public ?DateTime $dateRemind = null;
    public ?DateTime $dateReminded = null;
    public ?DateTime $dateUsed = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateExpires', 'dateRemind', 'dateReminded', 'dateUsed']);
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => Craft::t('pointz', 'Active'),
            self::STATUS_USED => Craft::t('pointz', 'Used'),
            self::STATUS_EXPIRED => Craft::t('pointz', 'Expired'),
            self::STATUS_REVOKED => Craft::t('pointz', 'Revoked'),
        ];
    }

    /**
     * "10%" or "€5.00".
     */
    public static function formatValue(string $type, float $amount, ?int $storeId = null): string
    {
        if ($type === Rule::COUPON_PERCENT) {
            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
        }

        return Plugin::getInstance()->getRedemption()->formatMoney($amount, $storeId);
    }

    public function getStatusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function getValueLabel(): string
    {
        return self::formatValue($this->type, $this->amount, $this->storeId);
    }

    /**
     * Whether the code can still be applied. The sweep is what marks a code expired; this answers
     * correctly in the hours between its date passing and the sweep running.
     */
    public function getIsUsable(?DateTime $when = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $when ??= new DateTime();

        return $this->dateExpires === null || $this->dateExpires > $when;
    }

    public function getUser(): ?User
    {
        return $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function getRule(): ?Rule
    {
        return $this->ruleId ? Plugin::getInstance()->getRules()->getRuleById($this->ruleId) : null;
    }

    public function getOrder(): ?Order
    {
        if (!$this->orderId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Order::find()->id($this->orderId)->status(null)->one();
    }

    public function getDiscount(): ?\craft\commerce\models\Discount
    {
        if (!$this->discountId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getDiscounts()->getDiscountById($this->discountId);
    }
}
