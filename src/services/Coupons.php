<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\adjusters\Discount as DiscountAdjuster;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\events\MatchOrderEvent;
use craft\commerce\models\Coupon as CommerceCoupon;
use craft\commerce\models\Discount;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Discount as DiscountRecord;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\events\CouponEvent;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;

/**
 * Coupons as a reward: one Commerce discount per rule, one single-use code per customer.
 *
 * Commerce coupons have no owner and no expiry of their own, which is why a store doing this by
 * hand ends up with a discount per customer — a thousand rows in the discounts table, most of
 * them dead. Pointz keeps one discount per rule and adds a code to it each time it issues one;
 * ownership and expiry live in `pointz_coupons`, and `discountMatchesOrder` enforces them, so a
 * code only discounts its owner's order and only until its date.
 *
 * The discount is created by the rule and **replaced, never edited**, when the rule's value
 * changes: a code already in a customer's inbox keeps the value it was issued with, the same way
 * a points redemption carries its count in the adjustment so a rate change cannot alter it.
 */
class Coupons extends Component
{
    /**
     * @event CouponEvent Raised after a coupon is issued and recorded.
     */
    public const EVENT_AFTER_ISSUE = 'afterIssue';

    /**
     * @event CouponEvent Raised before an expiry reminder is sent. Set `isValid` to false to send
     *                    it yourself; the coupon is marked reminded either way.
     */
    public const EVENT_BEFORE_REMIND = 'beforeRemind';

    public const MESSAGE_ISSUED = 'pointz_coupon_issued';
    public const MESSAGE_EXPIRING = 'pointz_coupon_expiring';

    /**
     * No 0/O or 1/I/L: these get read off a screen and typed into a checkout.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @var int[]|null */
    private ?array $_managedDiscountIds = null;

    /**
     * The discount a coupon rule's codes go on, created if the rule has none yet — or if its value
     * has changed since, or if somebody deleted it in Commerce.
     *
     * @return int|null Null when the rule does not award coupons.
     */
    public function ensureDiscount(Rule $rule): ?int
    {
        if (!$rule->awardsCoupon() || !$rule->id || !Plugin::commerceIsReady()) {
            return null;
        }

        $discounts = Commerce::getInstance()->getDiscounts();
        $current = $rule->couponDiscountId ? $discounts->getDiscountById($rule->couponDiscountId) : null;

        if ($current !== null && $this->_discountMatches($current, $rule)) {
            return $current->id;
        }

        $discount = new Discount();
        $discount->storeId = $rule->storeId;
        $discount->name = Craft::t('pointz', 'Pointz: {rule} ({value})', [
            'rule' => $rule->name,
            'value' => $rule->getCouponLabel(),
        ]);
        $discount->description = Craft::t('pointz', 'Created by Pointz for the “{rule}” rule. Each code belongs to one customer and is checked by Pointz; edit the conditions here if you like, but change the value on the rule.', [
            'rule' => $rule->name,
        ]);
        $discount->enabled = true;
        $discount->requireCouponCode = true;
        $discount->allPurchasables = true;
        $discount->allCategories = true;
        $discount->stopProcessing = false;

        if ($rule->couponType === Rule::COUPON_PERCENT) {
            // Commerce stores a percentage off as a negative fraction.
            $discount->percentDiscount = -((float)$rule->couponAmount / 100);
            $discount->percentageOffSubject = DiscountRecord::TYPE_DISCOUNTED_SALEPRICE;
            $discount->appliedTo = DiscountRecord::APPLIED_TO_ALL_LINE_ITEMS;
        } else {
            $discount->baseDiscount = -(float)$rule->couponAmount;
        }

        if (!$discounts->saveDiscount($discount)) {
            throw new PointzException('Could not create the coupon discount: ' . json_encode($discount->getErrors()));
        }

        $previous = $rule->couponDiscountId;
        $rule->couponDiscountId = $discount->id;
        Db::update(Table::RULES, ['couponDiscountId' => $discount->id], ['id' => $rule->id]);
        $this->_managedDiscountIds = null;

        // A replaced discount nobody holds a code for can go now. One with live codes stays until
        // the last of them is used or expires, and the sweep removes it then.
        if ($previous && $previous !== $discount->id) {
            $this->deleteDiscountIfIdle($previous);
        }

        return $discount->id;
    }

    /**
     * Issues one coupon to one customer.
     *
     * @param array{reference?: string|null, transactionId?: int|null} $options
     */
    public function issue(Rule $rule, User $user, int $storeId, array $options = []): Coupon
    {
        if (!$rule->awardsCoupon()) {
            throw new PointzException("Rule {$rule->handle} does not award coupons.");
        }

        $discountId = $this->ensureDiscount($rule);

        if ($discountId === null) {
            throw new PointzException('There is no discount to issue the coupon against.');
        }

        $code = $this->generateCode();

        $commerceCoupon = new CommerceCoupon();
        $commerceCoupon->discountId = $discountId;
        $commerceCoupon->code = $code;
        $commerceCoupon->maxUses = 1;
        $commerceCoupon->uses = 0;

        $discounts = Commerce::getInstance()->getDiscounts();
        $saved = method_exists($discounts, 'appendCouponCode')
            ? $discounts->appendCouponCode($discountId, $commerceCoupon)
            : Commerce::getInstance()->getCoupons()->saveCoupon($commerceCoupon);

        if (!$saved || !$commerceCoupon->id) {
            throw new PointzException('Could not add the coupon code: ' . json_encode($commerceCoupon->getErrors()));
        }

        $dateExpires = $rule->couponValidDays
            ? $this->now()->add(new DateInterval("P{$rule->couponValidDays}D"))
            : null;
        $dateRemind = ($dateExpires && $rule->couponRemindDays)
            ? (clone $dateExpires)->sub(new DateInterval("P{$rule->couponRemindDays}D"))
            : null;

        $coupon = new Coupon([
            'storeId' => $storeId,
            'userId' => $user->id,
            'ruleId' => $rule->id,
            'discountId' => $discountId,
            'couponId' => $commerceCoupon->id,
            'transactionId' => $options['transactionId'] ?? null,
            'code' => $code,
            'type' => $rule->couponType,
            'amount' => (float)$rule->couponAmount,
            'status' => Coupon::STATUS_ACTIVE,
            'reference' => $options['reference'] ?? null,
            'dateExpires' => $dateExpires,
            'dateRemind' => $dateRemind,
        ]);

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
        $coupon->uid = StringHelper::UUID();

        Craft::$app->getDb()->createCommand()->insert(Table::COUPONS, [
            'storeId' => $coupon->storeId,
            'userId' => $coupon->userId,
            'ruleId' => $coupon->ruleId,
            'discountId' => $coupon->discountId,
            'couponId' => $coupon->couponId,
            'transactionId' => $coupon->transactionId,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'amount' => $coupon->amount,
            'status' => $coupon->status,
            'reference' => $coupon->reference,
            'dateExpires' => $dateExpires ? Db::prepareDateForDb($dateExpires) : null,
            'dateRemind' => $dateRemind ? Db::prepareDateForDb($dateRemind) : null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $coupon->uid,
        ])->execute();

        $coupon->id = (int)Craft::$app->getDb()->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::COUPONS));
        $this->_managedDiscountIds = null;

        if ($this->hasEventHandlers(self::EVENT_AFTER_ISSUE)) {
            $this->trigger(self::EVENT_AFTER_ISSUE, new CouponEvent([
                'coupon' => $coupon,
                'user' => $user,
                'rule' => $rule,
            ]));
        }

        if ($rule->couponNotify) {
            $this->_send(self::MESSAGE_ISSUED, $coupon, $user, $rule);
        }

        return $coupon;
    }

    /**
     * The `discountMatchesOrder` handler: a Pointz code discounts only its owner's order, and only
     * while it is live. Discounts Pointz does not manage are left alone.
     *
     * The owner is the order's customer. Commerce makes whoever owns an email the customer of a
     * guest cart that types it, so a guest *can* use a code addressed to them without signing in —
     * which is the point, since the code was emailed — and anybody else would need both the
     * customer's email and the code itself.
     */
    public function enforceOwnership(MatchOrderEvent $event): void
    {
        $discount = $event->discount;
        $order = $event->order;

        if (!$discount->id || !in_array($discount->id, $this->getManagedDiscountIds(), true)) {
            return;
        }

        $coupon = $order->couponCode ? $this->getCouponByCode($order->couponCode) : null;

        if ($coupon === null || $coupon->discountId !== $discount->id || $coupon->userId !== $order->getCustomerId()) {
            $event->isValid = false;

            return;
        }

        // A completed order is judged as of the moment it was placed, and a code it has already
        // used is still its own.
        if ($coupon->status === Coupon::STATUS_USED && $coupon->orderId === $order->id) {
            return;
        }

        $when = ($order->isCompleted && $order->dateOrdered) ? $order->dateOrdered : new DateTime();

        if (!$coupon->getIsUsable($when)) {
            $event->isValid = false;
        }
    }

    /**
     * Marks the order's Pointz code used — once the discount actually applied. Commerce counts the
     * use itself; this records which order it went on.
     */
    public function markUsed(Order $order): ?Coupon
    {
        if (!$order->couponCode || !$order->id) {
            return null;
        }

        $coupon = $this->getCouponByCode($order->couponCode);

        if ($coupon === null || $coupon->status !== Coupon::STATUS_ACTIVE) {
            return null;
        }

        $applied = false;

        foreach ($order->getAdjustmentsByType(DiscountAdjuster::ADJUSTMENT_TYPE) as $adjustment) {
            $snapshot = $adjustment->sourceSnapshot ?? [];

            if ((int)($snapshot['discountUseId'] ?? $snapshot['id'] ?? 0) === $coupon->discountId) {
                $applied = true;
                break;
            }
        }

        if (!$applied) {
            return null;
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));

        Db::update(Table::COUPONS, [
            'status' => Coupon::STATUS_USED,
            'orderId' => $order->id,
            'dateUsed' => Db::prepareDateForDb($now),
        ], ['id' => $coupon->id, 'status' => Coupon::STATUS_ACTIVE]);

        $coupon->status = Coupon::STATUS_USED;
        $coupon->orderId = $order->id;
        $coupon->dateUsed = $now;

        return $coupon;
    }

    /**
     * Retires codes past their date and deletes them from Commerce, so the discount does not fill
     * up with codes nobody can use. The Pointz row stays, so the customer's history still says
     * what they had.
     *
     * @return int How many coupons expired.
     */
    public function expireDue(?int $limit = null): int
    {
        $limit ??= Plugin::getInstance()->getSettings()->sweepBatchSize;

        $rows = (new Query())
            ->select(['id', 'couponId'])
            ->from(Table::COUPONS)
            ->where(['status' => Coupon::STATUS_ACTIVE])
            ->andWhere(['not', ['dateExpires' => null]])
            ->andWhere(['<=', 'dateExpires', Db::prepareDateForDb(new DateTime())])
            ->limit($limit)
            ->all();

        $count = 0;

        foreach ($rows as $row) {
            $updated = Db::update(Table::COUPONS, ['status' => Coupon::STATUS_EXPIRED], [
                'id' => $row['id'],
                'status' => Coupon::STATUS_ACTIVE,
            ]);

            if ($updated) {
                $this->_deleteCommerceCoupon($row['couponId'] ? (int)$row['couponId'] : null);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sends the expiry reminders that are due. A reminder is stamped as sent whether Pointz sent
     * it or a handler took it over, so it goes out once.
     *
     * @return int How many reminders were due.
     */
    public function remindDue(?int $limit = null): int
    {
        $limit ??= Plugin::getInstance()->getSettings()->sweepBatchSize;
        $now = new DateTime();

        $rows = $this->_query()
            ->where(['status' => Coupon::STATUS_ACTIVE, 'dateReminded' => null])
            ->andWhere(['not', ['dateRemind' => null]])
            ->andWhere(['<=', 'dateRemind', Db::prepareDateForDb($now)])
            ->andWhere(['>', 'dateExpires', Db::prepareDateForDb($now)])
            ->limit($limit)
            ->all();

        $count = 0;

        foreach ($rows as $row) {
            $coupon = new Coupon($row);

            // Claim it first: two overlapping sweeps must not both send.
            $claimed = Db::update(Table::COUPONS, [
                'dateReminded' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
            ], ['id' => $coupon->id, 'dateReminded' => null]);

            if (!$claimed) {
                continue;
            }

            $user = $coupon->getUser();
            $rule = $coupon->getRule();
            $event = new CouponEvent(['coupon' => $coupon, 'user' => $user, 'rule' => $rule]);

            if ($this->hasEventHandlers(self::EVENT_BEFORE_REMIND)) {
                $this->trigger(self::EVENT_BEFORE_REMIND, $event);
            }

            if ($event->isValid && $user !== null) {
                $this->_send(self::MESSAGE_EXPIRING, $coupon, $user, $rule);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Removes discounts Pointz created that no rule issues against any more and nobody holds a
     * live code for — a replaced value, or a deleted rule, once its last code is spent or dead.
     *
     * @return int How many discounts were deleted.
     */
    public function cleanUpDiscounts(): int
    {
        $current = (new Query())
            ->select(['couponDiscountId'])
            ->from(Table::RULES)
            ->where(['not', ['couponDiscountId' => null]])
            ->column();

        $candidates = (new Query())
            ->select(['discountId'])
            ->distinct()
            ->from(Table::COUPONS)
            ->where(['not', ['discountId' => null]])
            ->andWhere(['not', ['discountId' => $current ?: [0]]])
            ->column();

        $count = 0;

        foreach ($candidates as $discountId) {
            if ($this->deleteDiscountIfIdle((int)$discountId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Deletes one of Pointz's discounts if no customer holds a live code on it. Deleting it takes
     * its Commerce coupons with it; Pointz's own rows keep the codes for the history.
     */
    public function deleteDiscountIfIdle(int $discountId): bool
    {
        $live = (new Query())
            ->from(Table::COUPONS)
            ->where(['discountId' => $discountId, 'status' => Coupon::STATUS_ACTIVE])
            ->exists();

        $current = (new Query())
            ->from(Table::RULES)
            ->where(['couponDiscountId' => $discountId])
            ->exists();

        if ($live || $current || !Plugin::commerceIsReady()) {
            return false;
        }

        $this->_managedDiscountIds = null;

        return Commerce::getInstance()->getDiscounts()->deleteDiscountById($discountId);
    }

    /**
     * Takes a live code back — support's tool for a coupon issued by mistake. Points a threshold
     * spent on it are not returned automatically; grant them back if they are owed.
     */
    public function revoke(Coupon $coupon): bool
    {
        if ($coupon->status !== Coupon::STATUS_ACTIVE) {
            return false;
        }

        $updated = Db::update(Table::COUPONS, ['status' => Coupon::STATUS_REVOKED], [
            'id' => $coupon->id,
            'status' => Coupon::STATUS_ACTIVE,
        ]);

        if ($updated) {
            $this->_deleteCommerceCoupon($coupon->couponId);
            $coupon->status = Coupon::STATUS_REVOKED;
        }

        return (bool)$updated;
    }

    public function getCouponById(int $id): ?Coupon
    {
        $row = $this->_query()->where(['id' => $id])->one();

        return $row ? new Coupon($row) : null;
    }

    /**
     * Codes are issued in upper case and matched without regard to it, as Commerce does.
     */
    public function getCouponByCode(string $code): ?Coupon
    {
        $row = $this->_query()->where(['code' => strtoupper(trim($code))])->one();

        return $row ? new Coupon($row) : null;
    }

    /**
     * @param array{userId?: int, storeId?: int, ruleId?: int, status?: string|string[], reference?: string} $criteria
     * @return Coupon[]
     */
    public function getCoupons(array $criteria = [], ?int $limit = 100): array
    {
        $query = $this->_query()->orderBy(['id' => SORT_DESC])->limit($limit);

        foreach (['userId', 'storeId', 'ruleId', 'status', 'reference'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return array_map(static fn(array $row) => new Coupon($row), $query->all());
    }

    /**
     * Whether a rule has already issued this customer a coupon — for this reference, or at all.
     */
    public function hasIssued(int $ruleId, int $userId, ?string $reference = null): bool
    {
        $query = (new Query())
            ->from(Table::COUPONS)
            ->where(['ruleId' => $ruleId, 'userId' => $userId]);

        if ($reference !== null) {
            $query->andWhere(['reference' => $reference]);
        }

        return $query->exists();
    }

    /**
     * Every discount Pointz manages: each rule's current one, and any older one still carrying
     * codes it issued.
     *
     * @return int[]
     */
    public function getManagedDiscountIds(): array
    {
        if ($this->_managedDiscountIds !== null) {
            return $this->_managedDiscountIds;
        }

        $fromRules = (new Query())
            ->select(['couponDiscountId'])
            ->from(Table::RULES)
            ->where(['not', ['couponDiscountId' => null]])
            ->column();

        $fromCoupons = (new Query())
            ->select(['discountId'])
            ->distinct()
            ->from(Table::COUPONS)
            ->where(['not', ['discountId' => null]])
            ->column();

        return $this->_managedDiscountIds = array_values(array_unique(array_map('intval', array_merge($fromRules, $fromCoupons))));
    }

    /**
     * A code nobody has: `PZ-` and eight characters that cannot be misread.
     */
    public function generateCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'PZ-';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            $taken = (new Query())->from(CommerceTable::COUPONS)->where(['code' => $code])->exists()
                || (new Query())->from(Table::COUPONS)->where(['code' => $code])->exists();

            if (!$taken) {
                return $code;
            }
        }

        throw new PointzException('Could not find an unused coupon code.');
    }

    /**
     * The two system messages, registered with Craft so they are edited under Utilities → System
     * Messages like Craft's own.
     *
     * @return array<int, array{key: string, heading: string, subject: string, body: string}>
     */
    public static function systemMessages(): array
    {
        return [
            [
                'key' => self::MESSAGE_ISSUED,
                'heading' => Craft::t('pointz', 'When Pointz issues a coupon'),
                'subject' => Craft::t('pointz', 'Your {{ value }} coupon'),
                'body' => Craft::t('pointz', "Hi {{ user.friendlyName }},\n\nHere is your {{ value }} coupon: **{{ coupon.code }}**\n\nEnter it at checkout.{% if coupon.dateExpires %} It can be used once, until {{ coupon.dateExpires|date('long') }}.{% else %} It can be used once.{% endif %}"),
            ],
            [
                'key' => self::MESSAGE_EXPIRING,
                'heading' => Craft::t('pointz', 'When a Pointz coupon is about to expire'),
                'subject' => Craft::t('pointz', 'Your {{ value }} coupon expires soon'),
                'body' => Craft::t('pointz', "Hi {{ user.friendlyName }},\n\nA reminder that your {{ value }} coupon **{{ coupon.code }}** expires on {{ coupon.dateExpires|date('long') }}.\n\nEnter it at checkout before then."),
            ],
        ];
    }

    public function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function _discountMatches(Discount $discount, Rule $rule): bool
    {
        if ($rule->couponType === Rule::COUPON_PERCENT) {
            return abs(abs((float)$discount->percentDiscount) * 100 - (float)$rule->couponAmount) < 0.0001
                && (float)$discount->baseDiscount == 0.0;
        }

        return abs(abs((float)$discount->baseDiscount) - (float)$rule->couponAmount) < 0.0001
            && (float)$discount->percentDiscount == 0.0;
    }

    private function _deleteCommerceCoupon(?int $couponId): void
    {
        if ($couponId === null || !Plugin::commerceIsReady()) {
            return;
        }

        Commerce::getInstance()->getCoupons()->deleteCouponById($couponId);
    }

    private function _send(string $key, Coupon $coupon, User $user, ?Rule $rule): void
    {
        if (!$user->email) {
            return;
        }

        try {
            Craft::$app->getMailer()
                ->composeFromKey($key, [
                    'user' => $user,
                    'coupon' => $coupon,
                    'rule' => $rule,
                    'value' => $coupon->getValueLabel(),
                ])
                ->setTo($user)
                ->send();
        } catch (\Throwable $e) {
            // An email that fails must not take the coupon with it — the code is issued and
            // recorded, and the customer can still see it on their account page.
            Craft::error("Pointz could not send $key for coupon {$coupon->id}: " . $e->getMessage(), 'pointz');
        }
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id',
                'storeId',
                'userId',
                'ruleId',
                'discountId',
                'couponId',
                'transactionId',
                'orderId',
                'code',
                'type',
                'amount',
                'status',
                'reference',
                'dateExpires',
                'dateRemind',
                'dateReminded',
                'dateUsed',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::COUPONS);
    }
}
