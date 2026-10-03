<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\elements\User;
use DateTime;
use justinholtweb\pointz\Plugin;

/**
 * One movement in the ledger. Append-only: nothing here is ever edited after it is written, and a
 * mistake is corrected by writing its opposite.
 */
class Transaction extends Model
{
    public const KIND_EARN = 'earn';
    public const KIND_REDEEM = 'redeem';
    public const KIND_EXPIRE = 'expire';
    public const KIND_ADJUST = 'adjust';
    public const KIND_REVERSE = 'reverse';
    public const KIND_REFUND = 'refund';
    public const KIND_REVOKE = 'revoke';

    /** Points spent by a threshold rule on the reward it pays: a coupon or store credit. */
    public const KIND_REWARD = 'reward';

    /** An opening balance brought over from another system by `pointz/import/balances`. */
    public const KIND_IMPORT = 'import';

    /** Written, but not counted in the balance yet. */
    public const STATUS_PENDING = 'pending';

    /** Counted in the balance. */
    public const STATUS_POSTED = 'posted';

    /** Undone by a later transaction, which carries `reversesId`. */
    public const STATUS_REVERSED = 'reversed';

    public ?int $id = null;
    public ?int $storeId = null;
    public ?int $userId = null;
    public string $currency = Rule::CURRENCY_POINTS;
    public string $kind = self::KIND_ADJUST;
    public float $amount = 0;
    public float $balanceAfter = 0;
    public string $status = self::STATUS_POSTED;
    public ?int $orderId = null;
    public ?int $ruleId = null;
    public ?int $authorId = null;
    public ?int $reversesId = null;
    public ?string $batchId = null;
    public ?string $reference = null;
    public ?string $note = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_EARN => Craft::t('pointz', 'Earned'),
            self::KIND_REDEEM => Craft::t('pointz', 'Redeemed'),
            self::KIND_EXPIRE => Craft::t('pointz', 'Expired'),
            self::KIND_ADJUST => Craft::t('pointz', 'Adjusted'),
            self::KIND_REVERSE => Craft::t('pointz', 'Reversed'),
            self::KIND_REFUND => Craft::t('pointz', 'Returned'),
            self::KIND_REVOKE => Craft::t('pointz', 'Revoked'),
            self::KIND_REWARD => Craft::t('pointz', 'Exchanged'),
            self::KIND_IMPORT => Craft::t('pointz', 'Imported'),
        ];
    }

    public function getKindLabel(): string
    {
        return self::kinds()[$this->kind] ?? $this->kind;
    }

    public function getOrder(): ?Order
    {
        if (!$this->orderId || !Plugin::commerceIsReady()) {
            return null;
        }

        return Order::find()->id($this->orderId)->status(null)->one();
    }

    public function getUser(): ?User
    {
        return $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function getAuthor(): ?User
    {
        return $this->authorId ? Craft::$app->getUsers()->getUserById($this->authorId) : null;
    }

    public function getRule(): ?Rule
    {
        return $this->ruleId ? Plugin::getInstance()->getRules()->getRuleById($this->ruleId) : null;
    }

    public function isCredit(): bool
    {
        return $this->currency === Rule::CURRENCY_CREDIT;
    }
}
