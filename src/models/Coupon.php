<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\twig\AllowedInSandbox;
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
 *
 * The properties and getters an email template reads are marked for Craft's Twig sandbox, so the
 * coupon emails render with `enableTwigSandbox` on. Craft before 5.9 ignores the attribute.
 */
class Coupon extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    /** A reminder that the code expires soon. */
    public const REMIND_EXPIRY = 'expiry';

    /** A reminder, counted from the day it was issued, that the code is still there to be used. */
    public const REMIND_ISSUED = 'issued';

    #[AllowedInSandbox]
    public ?int $id = null;
    public ?int $storeId = null;
    public ?int $userId = null;
    public ?int $ruleId = null;
    public ?int $discountId = null;
    public ?int $couponId = null;
    public ?int $transactionId = null;
    public ?int $orderId = null;
    #[AllowedInSandbox]
    public string $code = '';
    #[AllowedInSandbox]
    public string $type = Rule::COUPON_PERCENT;
    #[AllowedInSandbox]
    public float $amount = 0;
    #[AllowedInSandbox]
    public string $status = self::STATUS_ACTIVE;
    public ?string $reference = null;
    #[AllowedInSandbox]
    public ?DateTime $dateExpires = null;

    /** The next reminder due, or null when none is left to send. */
    public ?DateTime $dateRemind = null;

    /** When the last reminder went out. */
    public ?DateTime $dateReminded = null;
    #[AllowedInSandbox]
    public ?DateTime $dateUsed = null;
    #[AllowedInSandbox]
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Every reminder the code was given when it was issued, sent or not, as
     * `[['date' => 'Y-m-d H:i:s' (UTC), 'kind' => 'expiry'|'issued'], …]` in date order. Null for a
     * coupon issued before 5.3, whose only reminder is `dateRemind`.
     *
     * @var array<int, array{date?: string, kind?: string}>|null
     */
    public ?array $reminders = null;

    /** Whether Pointz limited an imported code to one use, which a revert undoes. */
    public bool $madeSingleUse = false;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        if (isset($config['reminders']) && is_string($config['reminders'])) {
            $config['reminders'] = Json::decodeIfJson($config['reminders']) ?: null;
        }

        parent::__construct($config);
    }

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

    /**
     * The reminder schedule, oldest first, with dates in the system time zone.
     *
     * @return array<int, array{date: DateTime, kind: string}>
     */
    public function getReminderSchedule(): array
    {
        if ($this->reminders === null) {
            return $this->dateRemind ? [['date' => clone $this->dateRemind, 'kind' => self::REMIND_EXPIRY]] : [];
        }

        $schedule = [];

        foreach ($this->reminders as $reminder) {
            $date = DateTimeHelper::toDateTime($reminder['date'] ?? null);

            if ($date) {
                $schedule[] = ['date' => $date, 'kind' => $reminder['kind'] ?? self::REMIND_ISSUED];
            }
        }

        usort($schedule, static fn(array $a, array $b) => $a['date'] <=> $b['date']);

        return $schedule;
    }

    #[AllowedInSandbox]
    public function getStatusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    #[AllowedInSandbox]
    public function getValueLabel(): string
    {
        return self::formatValue($this->type, $this->amount, $this->storeId);
    }

    /**
     * Whether the code can still be applied. The sweep is what marks a code expired; this answers
     * correctly in the hours between its date passing and the sweep running.
     */
    #[AllowedInSandbox]
    public function getIsUsable(?DateTime $when = null): bool
    {
        // No Commerce coupon behind it means the discount was deleted in Commerce.
        if ($this->status !== self::STATUS_ACTIVE || $this->couponId === null) {
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
