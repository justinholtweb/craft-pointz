<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;

/**
 * Pointz settings.
 *
 * These are global rather than per-store. Earning is per-store because rules are, but the
 * redemption rate, the expiry policy and the refund behaviour are one set of numbers for the
 * install — which is the honest position for a plugin whose balances are per-store but whose
 * customers are not.
 */
class Settings extends Model
{
    /** Points are earned the moment the order completes. */
    public const AWARD_ON_COMPLETE = 'complete';

    /** Points are earned when the order is paid in full. */
    public const AWARD_ON_PAID = 'paid';

    /** Points are earned when the order reaches a chosen status. */
    public const AWARD_ON_STATUS = 'status';

    /** Spend what the customer actually has, and note the shortfall on the order. */
    public const SHORTFALL_CLAMP = 'clamp';

    /** Refuse to complete the order. */
    public const SHORTFALL_THROW = 'throw';

    /** Take back a share of the earned value matching the share of the order refunded. */
    public const REVERSAL_PROPORTIONAL = 'proportional';

    /** Take back everything the order earned, however small the refund. */
    public const REVERSAL_FULL = 'full';

    /** Leave earned value alone on a refund. */
    public const REVERSAL_NONE = 'none';

    public const BASE_ITEM_SUBTOTAL = 'itemSubtotal';
    public const BASE_TOTAL = 'total';
    public const BASE_TOTAL_LESS_SHIPPING = 'totalLessShipping';
    public const BASE_TOTAL_LESS_SHIPPING_TAX = 'totalLessShippingTax';

    /**
     * @var bool Whether completed orders earn anything at all. Off leaves the plugin installed and
     *           usable for manual adjustments and backfills without touching live checkouts.
     */
    public bool $earningEnabled = true;

    /**
     * @var string When an order's points become the customer's.
     */
    public string $awardOn = self::AWARD_ON_COMPLETE;

    /**
     * @var string|null The order status handle that releases points, when `awardOn` is `status`.
     */
    public ?string $awardOnStatus = null;

    /**
     * @var int Days a new lot stays pending before it can be spent. A refund window, in effect:
     *          nothing earned can be spent until it is unlikely to be taken back.
     */
    public int $holdDays = 0;

    /**
     * @var string What a customer's points are called, singular.
     */
    public string $pointsLabel = 'point';

    /**
     * @var string What a customer's points are called, plural.
     */
    public string $pointsLabelPlural = 'points';

    /**
     * @var string What the credit balance is called.
     */
    public string $creditLabel = 'store credit';

    /**
     * @var bool Whether value paid for with points or credit still earns. Off by default: paying
     *           with points and earning points on the same money is a loop the store funds twice.
     */
    public bool $earnOnRedeemedValue = false;

    /**
     * @var bool Whether points may be spent at checkout.
     */
    public bool $redemptionEnabled = true;

    /**
     * @var float How many points buy one unit of the store's currency.
     */
    public float $pointsPerUnit = 100;

    /**
     * @var float The fewest points a customer may spend in one order.
     */
    public float $minPointsToRedeem = 0;

    /**
     * @var float Points may only be spent in multiples of this. 1 means any number.
     */
    public float $redeemBlockSize = 1;

    /**
     * @var float|null The most of an order's discountable base that points may cover, as a
     *                 percentage. Null means all of it.
     */
    public ?float $maxRedemptionPercent = null;

    /**
     * @var string Which figure redemption is capped against — and therefore whether points can
     *             pay for shipping and tax.
     */
    public string $redeemableBase = self::BASE_TOTAL_LESS_SHIPPING_TAX;

    /**
     * @var bool Whether store credit may be spent at checkout. Pro.
     */
    public bool $creditRedemptionEnabled = true;

    /**
     * @var string What to do when an order completes carrying a redemption the balance can no
     *             longer cover.
     */
    public string $onShortfall = self::SHORTFALL_CLAMP;

    /**
     * @var bool Whether earned value expires at all.
     */
    public bool $expiryEnabled = false;

    /**
     * @var int|null Days until earned value expires, unless its rule overrides it.
     */
    public ?int $expireAfterDays = null;

    /**
     * @var int|null Days of no earning and no spending after which a whole balance expires. Pro.
     */
    public ?int $inactivityExpiryDays = null;

    /**
     * @var int|null How many days before expiry a warning is worth sending. Pro.
     */
    public ?int $expiryWarningDays = null;

    /**
     * @var string What a refund does to the value the order earned.
     */
    public string $onRefund = self::REVERSAL_PROPORTIONAL;

    /**
     * @var bool Whether a refund also hands back the points the order spent. On by default: a
     *           customer who paid partly in points and got their money back is owed the points.
     */
    public bool $returnRedeemedOnRefund = true;

    /**
     * @var bool Whether guest customers earn. Commerce gives every order a customer account, so
     *           off means a guest's points wait for them to activate it — and there is nowhere
     *           for them to see the balance until they do.
     */
    public bool $earnAsGuest = true;

    /**
     * @var int Seconds to wait for an account's lock before giving up.
     */
    public int $lockTimeout = 5;

    /**
     * @var int How many accounts an expiry or promotion sweep handles per batch.
     */
    public int $sweepBatchSize = 200;

    /**
     * @var bool Whether every movement is also written to the logs.
     */
    public bool $logTransactions = false;

    /**
     * @return array<string, string>
     */
    public static function awardMoments(): array
    {
        return [
            self::AWARD_ON_COMPLETE => Craft::t('pointz', 'The order completes'),
            self::AWARD_ON_PAID => Craft::t('pointz', 'The order is paid in full'),
            self::AWARD_ON_STATUS => Craft::t('pointz', 'The order reaches a status'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function shortfallModes(): array
    {
        return [
            self::SHORTFALL_CLAMP => Craft::t('pointz', 'Spend what is there and note the shortfall'),
            self::SHORTFALL_THROW => Craft::t('pointz', 'Fail the order completion'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reversalModes(): array
    {
        return [
            self::REVERSAL_PROPORTIONAL => Craft::t('pointz', 'Take back the refunded share'),
            self::REVERSAL_FULL => Craft::t('pointz', 'Take back everything the order earned'),
            self::REVERSAL_NONE => Craft::t('pointz', 'Leave earned value alone'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function redeemableBases(): array
    {
        return [
            self::BASE_ITEM_SUBTOTAL => Craft::t('pointz', 'Item subtotal'),
            self::BASE_TOTAL => Craft::t('pointz', 'Order total'),
            self::BASE_TOTAL_LESS_SHIPPING => Craft::t('pointz', 'Order total, less shipping'),
            self::BASE_TOTAL_LESS_SHIPPING_TAX => Craft::t('pointz', 'Order total, less shipping and tax'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['earningEnabled', 'earnOnRedeemedValue', 'redemptionEnabled', 'creditRedemptionEnabled', 'expiryEnabled', 'returnRedeemedOnRefund', 'earnAsGuest', 'logTransactions'], 'boolean'],
            [['awardOn'], 'in', 'range' => array_keys(self::awardMoments())],
            [['onShortfall'], 'in', 'range' => array_keys(self::shortfallModes())],
            [['onRefund'], 'in', 'range' => array_keys(self::reversalModes())],
            [['redeemableBase'], 'in', 'range' => array_keys(self::redeemableBases())],
            [['pointsPerUnit'], 'number', 'min' => 0.00001],
            [['minPointsToRedeem'], 'number', 'min' => 0],
            [['redeemBlockSize'], 'number', 'min' => 0.00001],
            [['maxRedemptionPercent'], 'number', 'min' => 0, 'max' => 100],
            [['holdDays'], 'integer', 'min' => 0],
            [['expireAfterDays', 'inactivityExpiryDays', 'expiryWarningDays'], 'integer', 'min' => 1],
            [['lockTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['sweepBatchSize'], 'integer', 'min' => 1, 'max' => 5000],
            [['pointsLabel', 'pointsLabelPlural', 'creditLabel'], 'string', 'max' => 60],
            [['awardOnStatus'], 'validateAwardStatus', 'skipOnEmpty' => false],
            [['expireAfterDays'], 'validateExpiry', 'skipOnEmpty' => false],
            // Never `required`: a fresh install has to be able to save the settings screen before
            // every field on it has been thought about.
            [['awardOnStatus'], 'safe'],
        ];
    }

    public function validateAwardStatus(string $attribute): void
    {
        if ($this->awardOn === self::AWARD_ON_STATUS && !$this->awardOnStatus) {
            $this->addError($attribute, Craft::t('pointz', 'Choose the status that releases points.'));
        }
    }

    public function validateExpiry(string $attribute): void
    {
        if ($this->expiryEnabled && $this->expireAfterDays === null && $this->inactivityExpiryDays === null) {
            $this->addError($attribute, Craft::t('pointz', 'Expiry needs either a lifetime or an inactivity window.'));
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'earningEnabled' => Craft::t('pointz', 'Award points on completed orders'),
            'awardOn' => Craft::t('pointz', 'Award when'),
            'earnOnRedeemedValue' => Craft::t('pointz', 'Earn on value paid with points or credit'),
            'awardOnStatus' => Craft::t('pointz', 'Releasing status'),
            'holdDays' => Craft::t('pointz', 'Hold new points for'),
            'pointsLabel' => Craft::t('pointz', 'Name for one point'),
            'pointsLabelPlural' => Craft::t('pointz', 'Name for several points'),
            'creditLabel' => Craft::t('pointz', 'Name for store credit'),
            'redemptionEnabled' => Craft::t('pointz', 'Let customers spend points at checkout'),
            'pointsPerUnit' => Craft::t('pointz', 'Points per unit of currency'),
            'minPointsToRedeem' => Craft::t('pointz', 'Minimum redemption'),
            'redeemBlockSize' => Craft::t('pointz', 'Redeem in multiples of'),
            'maxRedemptionPercent' => Craft::t('pointz', 'Maximum share of an order'),
            'redeemableBase' => Craft::t('pointz', 'Redeemable against'),
            'creditRedemptionEnabled' => Craft::t('pointz', 'Let customers spend store credit'),
            'onShortfall' => Craft::t('pointz', 'If the balance falls short'),
            'expiryEnabled' => Craft::t('pointz', 'Expire earned value'),
            'expireAfterDays' => Craft::t('pointz', 'Expires after'),
            'inactivityExpiryDays' => Craft::t('pointz', 'Expire an idle balance after'),
            'expiryWarningDays' => Craft::t('pointz', 'Warn this long before expiry'),
            'onRefund' => Craft::t('pointz', 'When an order is refunded'),
            'returnRedeemedOnRefund' => Craft::t('pointz', 'Hand back points the order spent'),
            'earnAsGuest' => Craft::t('pointz', 'Guest checkouts earn'),
            'lockTimeout' => Craft::t('pointz', 'Lock timeout'),
            'sweepBatchSize' => Craft::t('pointz', 'Sweep batch size'),
            'logTransactions' => Craft::t('pointz', 'Log every movement'),
        ];
    }

    /**
     * The label for a quantity of points, in the store's own words.
     */
    public function label(float $amount): string
    {
        return abs($amount) == 1.0 ? $this->pointsLabel : $this->pointsLabelPlural;
    }
}
