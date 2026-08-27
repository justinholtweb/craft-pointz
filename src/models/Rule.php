<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\elements\conditions\users\UserCondition;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use DateTime;
use justinholtweb\pointz\elements\conditions\orders\PointzOrderCondition;
use justinholtweb\pointz\elements\conditions\purchasables\PointzPurchasableCondition;
use justinholtweb\pointz\Plugin;
use justinholtweb\pointz\records\RuleRecord;

/**
 * An earning rule: what a customer gets, for doing what, and under which conditions.
 *
 * A rule computes an award; it never writes one. `services\Earning` owns the writing, so a preview
 * on a product page and a real accrual at checkout run the same arithmetic.
 */
class Rule extends Model
{
    public const EVENT_ORDER = 'order';
    public const EVENT_SIGNUP = 'signup';

    public const SCOPE_ORDER = 'order';
    public const SCOPE_LINE_ITEM = 'lineItem';

    public const CURRENCY_POINTS = 'points';
    public const CURRENCY_CREDIT = 'credit';

    public const CALC_RATIO = 'ratio';
    public const CALC_FIXED = 'fixed';

    public const BASIS_ITEM_SUBTOTAL = 'itemSubtotal';
    public const BASIS_TOTAL = 'total';
    public const BASIS_TOTAL_LESS_SHIPPING = 'totalLessShipping';
    public const BASIS_TOTAL_LESS_SHIPPING_TAX = 'totalLessShippingTax';
    public const BASIS_LINE_ITEM_SUBTOTAL = 'lineItemSubtotal';
    public const BASIS_LINE_ITEM_QTY = 'lineItemQty';

    public const ROUND_DOWN = 'down';
    public const ROUND_NEAREST = 'nearest';
    public const ROUND_UP = 'up';

    public const PERIOD_EVER = 'ever';
    public const PERIOD_YEAR = 'year';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_DAY = 'day';

    public ?int $id = null;
    public ?int $storeId = null;
    public string $name = '';
    public string $handle = '';
    public bool $enabled = false;
    public ?int $sortOrder = null;
    public string $event = self::EVENT_ORDER;
    public string $scope = self::SCOPE_ORDER;
    public string $currency = self::CURRENCY_POINTS;
    public string $calculation = self::CALC_RATIO;
    public string $basis = self::BASIS_ITEM_SUBTOTAL;
    public float $rate = 1;
    public float $multiplier = 1;
    public string $rounding = self::ROUND_DOWN;
    public ?float $minAward = null;
    public ?float $maxAward = null;
    public ?float $maxPerUser = null;
    public string $maxPerUserPeriod = self::PERIOD_EVER;
    public bool $firstOrderOnly = false;
    public bool $stopProcessing = false;
    public ?int $expireAfterDays = null;
    public ?DateTime $dateFrom = null;
    public ?DateTime $dateTo = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private mixed $_orderCondition = null;
    private mixed $_userCondition = null;
    private mixed $_purchasableCondition = null;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Conditions arrive as JSON from the database and as arrays from the CP; both go through
        // the setters, which is why they are pulled out before Model typecasts the rest.
        foreach (['orderCondition', 'userCondition', 'purchasableCondition'] as $key) {
            if (array_key_exists($key, $config)) {
                $value = $config[$key];
                unset($config[$key]);
                $config[$key] = $value;
            }
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateFrom', 'dateTo']);
    }

    /**
     * @return array<string, string>
     */
    public static function events(): array
    {
        return [
            self::EVENT_ORDER => Craft::t('pointz', 'A completed order'),
            self::EVENT_SIGNUP => Craft::t('pointz', 'A new customer account'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_ORDER => Craft::t('pointz', 'Once per order'),
            self::SCOPE_LINE_ITEM => Craft::t('pointz', 'Once per matching line item'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function currencies(): array
    {
        return [
            self::CURRENCY_POINTS => Craft::t('pointz', 'Points'),
            self::CURRENCY_CREDIT => Craft::t('pointz', 'Store credit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function calculations(): array
    {
        return [
            self::CALC_RATIO => Craft::t('pointz', 'A rate per unit of value'),
            self::CALC_FIXED => Craft::t('pointz', 'A fixed amount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function bases(): array
    {
        return [
            self::BASIS_ITEM_SUBTOTAL => Craft::t('pointz', 'Item subtotal'),
            self::BASIS_TOTAL => Craft::t('pointz', 'Order total'),
            self::BASIS_TOTAL_LESS_SHIPPING => Craft::t('pointz', 'Order total, less shipping'),
            self::BASIS_TOTAL_LESS_SHIPPING_TAX => Craft::t('pointz', 'Order total, less shipping and tax'),
            self::BASIS_LINE_ITEM_SUBTOTAL => Craft::t('pointz', 'Line item subtotal'),
            self::BASIS_LINE_ITEM_QTY => Craft::t('pointz', 'Line item quantity'),
        ];
    }

    /**
     * The bases that only make sense on a line-item rule, and the ones that only make sense on an
     * order rule. Choosing the wrong pair is the single easiest way to write a rule that silently
     * awards nothing, so validation rejects it rather than rounding it to zero.
     *
     * @return string[]
     */
    public static function basesForScope(string $scope): array
    {
        if ($scope === self::SCOPE_LINE_ITEM) {
            return [self::BASIS_LINE_ITEM_SUBTOTAL, self::BASIS_LINE_ITEM_QTY];
        }

        return [
            self::BASIS_ITEM_SUBTOTAL,
            self::BASIS_TOTAL,
            self::BASIS_TOTAL_LESS_SHIPPING,
            self::BASIS_TOTAL_LESS_SHIPPING_TAX,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roundings(): array
    {
        return [
            self::ROUND_DOWN => Craft::t('pointz', 'Round down'),
            self::ROUND_NEAREST => Craft::t('pointz', 'Round to nearest'),
            self::ROUND_UP => Craft::t('pointz', 'Round up'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function periods(): array
    {
        return [
            self::PERIOD_EVER => Craft::t('pointz', 'Ever'),
            self::PERIOD_YEAR => Craft::t('pointz', 'Per calendar year'),
            self::PERIOD_MONTH => Craft::t('pointz', 'Per calendar month'),
            self::PERIOD_WEEK => Craft::t('pointz', 'Per week'),
            self::PERIOD_DAY => Craft::t('pointz', 'Per day'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'storeId', 'event', 'scope', 'currency', 'calculation', 'rounding'], 'required'],
            [['handle'], HandleValidator::class],
            [['handle'], UniqueValidator::class, 'targetClass' => RuleRecord::class, 'targetAttribute' => ['handle', 'storeId']],
            [['event'], 'in', 'range' => array_keys(self::events())],
            [['scope'], 'in', 'range' => array_keys(self::scopes())],
            [['currency'], 'in', 'range' => array_keys(self::currencies())],
            [['calculation'], 'in', 'range' => array_keys(self::calculations())],
            [['rounding'], 'in', 'range' => array_keys(self::roundings())],
            [['maxPerUserPeriod'], 'in', 'range' => array_keys(self::periods())],
            [['basis'], 'validateBasis'],
            [['rate'], 'number'],
            [['multiplier'], 'number', 'min' => 0],
            [['minAward', 'maxAward', 'maxPerUser'], 'number', 'min' => 0],
            [['expireAfterDays'], 'integer', 'min' => 1],
            [['enabled', 'firstOrderOnly', 'stopProcessing'], 'boolean'],
            [['dateTo'], 'validateWindow'],
            [['maxAward'], 'validateAwardRange'],
            [['sortOrder', 'id', 'uid', 'dateFrom', 'dateCreated', 'dateUpdated'], 'safe'],
        ];
    }

    public function validateBasis(string $attribute): void
    {
        // A fixed award ignores the basis entirely, so there is nothing to be wrong about.
        if ($this->calculation === self::CALC_FIXED) {
            return;
        }

        if (!in_array($this->basis, self::basesForScope($this->scope), true)) {
            $this->addError($attribute, Craft::t('pointz', 'That value is not available for this rule’s scope.'));
        }
    }

    /**
     * Yii's CompareValidator stringifies its operands, which throws on two DateTimes.
     */
    public function validateWindow(string $attribute): void
    {
        if ($this->dateFrom && $this->dateTo && $this->dateTo < $this->dateFrom) {
            $this->addError($attribute, Craft::t('pointz', 'The end of the window must come after its start.'));
        }
    }

    public function validateAwardRange(string $attribute): void
    {
        if ($this->minAward !== null && $this->maxAward !== null && $this->maxAward < $this->minAward) {
            $this->addError($attribute, Craft::t('pointz', 'The maximum must be at least the minimum.'));
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('pointz', 'Name'),
            'handle' => Craft::t('pointz', 'Handle'),
            'event' => Craft::t('pointz', 'Earned for'),
            'scope' => Craft::t('pointz', 'Applies'),
            'currency' => Craft::t('pointz', 'Awards'),
            'calculation' => Craft::t('pointz', 'Calculated as'),
            'basis' => Craft::t('pointz', 'Based on'),
            'rate' => Craft::t('pointz', 'Rate'),
            'multiplier' => Craft::t('pointz', 'Multiplier'),
            'rounding' => Craft::t('pointz', 'Rounding'),
            'minAward' => Craft::t('pointz', 'Minimum award'),
            'maxAward' => Craft::t('pointz', 'Maximum award'),
            'maxPerUser' => Craft::t('pointz', 'Maximum per customer'),
            'maxPerUserPeriod' => Craft::t('pointz', 'Counted'),
            'expireAfterDays' => Craft::t('pointz', 'Expires after'),
            'dateFrom' => Craft::t('pointz', 'Starts'),
            'dateTo' => Craft::t('pointz', 'Ends'),
        ];
    }

    public function getOrderCondition(): PointzOrderCondition
    {
        $condition = $this->_orderCondition;

        if ($condition === null) {
            $condition = new PointzOrderCondition();
        } elseif (is_string($condition)) {
            $condition = Craft::$app->getConditions()->createCondition(Json::decode($condition));
        } elseif (is_array($condition)) {
            $condition = Craft::$app->getConditions()->createCondition($condition);
        }

        /** @var PointzOrderCondition $condition */
        $condition->mainTag = 'div';
        $condition->name = 'orderCondition';
        $condition->storeId = $this->storeId;

        $this->_orderCondition = $condition;

        return $condition;
    }

    public function setOrderCondition(mixed $condition): void
    {
        $this->_orderCondition = $condition;
    }

    public function getUserCondition(): UserCondition
    {
        $condition = $this->_userCondition;

        if ($condition === null) {
            $condition = new UserCondition();
        } elseif (is_string($condition)) {
            $condition = Craft::$app->getConditions()->createCondition(Json::decode($condition));
        } elseif (is_array($condition)) {
            $condition = Craft::$app->getConditions()->createCondition($condition);
        }

        /** @var UserCondition $condition */
        $condition->mainTag = 'div';
        $condition->name = 'userCondition';

        $this->_userCondition = $condition;

        return $condition;
    }

    public function setUserCondition(mixed $condition): void
    {
        $this->_userCondition = $condition;
    }

    public function getPurchasableCondition(): PointzPurchasableCondition
    {
        $condition = $this->_purchasableCondition;

        if ($condition === null) {
            $condition = new PointzPurchasableCondition();
        } elseif (is_string($condition)) {
            $condition = Craft::$app->getConditions()->createCondition(Json::decode($condition));
        } elseif (is_array($condition)) {
            $condition = Craft::$app->getConditions()->createCondition($condition);
        }

        /** @var PointzPurchasableCondition $condition */
        $condition->mainTag = 'div';
        $condition->name = 'purchasableCondition';

        $this->_purchasableCondition = $condition;

        return $condition;
    }

    public function setPurchasableCondition(mixed $condition): void
    {
        $this->_purchasableCondition = $condition;
    }

    /**
     * Whether the rule is inside its campaign window right now.
     */
    public function isInWindow(?DateTime $when = null): bool
    {
        $when ??= new DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone()));

        if ($this->dateFrom && $when < $this->dateFrom) {
            return false;
        }

        if ($this->dateTo && $when > $this->dateTo) {
            return false;
        }

        return true;
    }

    /**
     * Whether the order satisfies the rule's order condition. An empty condition matches
     * everything, which is what the seeded rule relies on.
     */
    public function matchesOrder(Order $order): bool
    {
        $condition = $this->getOrderCondition();

        if (!$condition->getConditionRules()) {
            return true;
        }

        return $condition->matchElement($order);
    }

    public function matchesUser(?User $user): bool
    {
        $condition = $this->getUserCondition();

        if (!$condition->getConditionRules()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $condition->matchElement($user);
    }

    /**
     * Line-item rules match against the purchasable. A purchasable that is not an element — a
     * plugin's own virtual purchasable — cannot be matched by an element condition, so an
     * *unconditional* line-item rule still covers it and a conditional one does not.
     */
    public function matchesPurchasable(mixed $purchasable): bool
    {
        $condition = $this->getPurchasableCondition();

        if (!$condition->getConditionRules()) {
            return true;
        }

        if (!$purchasable instanceof \craft\base\ElementInterface) {
            return false;
        }

        return $condition->matchElement($purchasable);
    }

    /**
     * The condition JSON as it is stored. Null rather than an empty condition's JSON, so "no
     * condition" is one value in the database rather than two.
     */
    public function getConditionJson(string $which): ?string
    {
        $condition = match ($which) {
            'order' => $this->getOrderCondition(),
            'user' => $this->getUserCondition(),
            'purchasable' => $this->getPurchasableCondition(),
        };

        if (!$condition->getConditionRules()) {
            return null;
        }

        return Json::encode($condition->getConfig());
    }

    public function getCpEditUrl(): string
    {
        $store = Plugin::getInstance()->getRules()->getStoreHandle($this->storeId);

        if ($this->id === null) {
            return sprintf('pointz/rules/%s/new', $store);
        }

        return sprintf('pointz/rules/%s/%s', $store, $this->id);
    }

    /**
     * A handle suggestion for a new rule, so the CP can fill it in from the name.
     */
    public function suggestHandle(): string
    {
        return StringHelper::toCamelCase($this->name ?: 'rule');
    }
}
