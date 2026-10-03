<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\events\RewardEvent;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * Everything a customer can be rewarded for that is not an order: signing up, a custom event, an
 * approved review, a birthday, and reaching a points balance.
 *
 * Each trigger is a rule like any other — conditions, a window, caps, an expiry — and each pays
 * out through `grant()`, which hands out points, credit or a coupon and never pays the same rule
 * twice for the same thing. "The same thing" is a reference: `stars:review:42`, `birthday:2026`,
 * or whatever a custom event's caller passes. No reference means once per customer, ever.
 */
class Rewards extends Component
{
    /**
     * @event RewardEvent Raised before a rule that is not an order pays out. Set `isValid` to false
     *                    to skip it.
     */
    public const EVENT_BEFORE_REWARD = 'beforeReward';

    /**
     * A birthday reward is still paid this many days after the day itself, so a sweep that missed
     * a night — or a customer who registers the week after — is not skipped for a year.
     */
    public const BIRTHDAY_GRACE_DAYS = 6;

    /**
     * The most times one threshold rule fires in one evaluation. A customer on 230 points and a
     * "every 50 points" rule gets four rewards, not one — but never an unbounded loop.
     */
    public const THRESHOLD_MAX_REPEATS = 20;

    /**
     * Pays out one rule to one customer.
     *
     * @param array{note?: string|null, once?: bool, transactionId?: int|null} $options `once` false
     *        skips the "already paid for this reference" check — for a threshold, whose spend is
     *        what makes each payout unique.
     */
    public function grant(Rule $rule, User $user, int $storeId, ?string $reference = null, array $context = [], array $options = []): Transaction|Coupon|null
    {
        if (!$rule->matchesUser($user)) {
            return null;
        }

        if (($options['once'] ?? true) && $this->hasRewarded($rule, $user->id, $reference)) {
            return null;
        }

        $event = new RewardEvent([
            'rule' => $rule,
            'user' => $user,
            'storeId' => $storeId,
            'reference' => $reference,
            'context' => $context,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REWARD)) {
            $this->trigger(self::EVENT_BEFORE_REWARD, $event);
        }

        if (!$event->isValid) {
            return null;
        }

        if ($rule->awardsCoupon()) {
            return Plugin::getInstance()->getCoupons()->issue($rule, $user, $storeId, [
                'reference' => $reference,
                'transactionId' => $options['transactionId'] ?? null,
            ]);
        }

        $amount = Plugin::getInstance()->getEarning()->fixedAward($rule, $storeId);

        if ($rule->maxPerUser !== null) {
            $headroom = $rule->maxPerUser - Plugin::getInstance()->getRules()->getAwardedInPeriod($rule, $user->id);
            $amount = min($amount, max(0, $headroom));
        }

        if ($amount <= 0) {
            return null;
        }

        return Plugin::getInstance()->getLedger()->credit($user->id, $storeId, $rule->currency, $amount, [
            'kind' => Transaction::KIND_EARN,
            'ruleId' => $rule->id,
            'reference' => $reference,
            'dateExpires' => Plugin::getInstance()->getLifecycle()->expiryDateFor($rule->expireAfterDays),
            'note' => $options['note'] ?? $rule->name,
        ]);
    }

    /**
     * Whether a rule has already paid this customer for this reference — or, with no reference,
     * for anything at all. Both the ledger and the coupons are asked, so a rule switched from
     * points to a coupon does not pay out again.
     */
    public function hasRewarded(Rule $rule, int $userId, ?string $reference = null): bool
    {
        $criteria = ['userId' => $userId, 'ruleId' => $rule->id, 'kind' => Transaction::KIND_EARN];
        $query = Plugin::getInstance()->getLedger()->getTransactionsQuery($criteria);

        if ($reference !== null) {
            $query->andWhere(['reference' => $reference]);
        }

        return $query->exists()
            || Plugin::getInstance()->getCoupons()->hasIssued($rule->id, $userId, $reference);
    }

    /**
     * Runs every active rule for a trigger, in precedence order.
     *
     * @param callable(Rule): bool|null $filter Which of the trigger's rules apply this time.
     * @return array<int, Transaction|Coupon>
     */
    public function awardForEvent(string $event, User $user, int $storeId, ?string $reference = null, array $context = [], bool $firstOnly = false, ?callable $filter = null): array
    {
        if (!Plugin::getInstance()->getSettings()->earningEnabled) {
            return [];
        }

        $results = [];

        foreach (Plugin::getInstance()->getRules()->getActiveRules($storeId, $event) as $rule) {
            if ($filter !== null && !$filter($rule)) {
                continue;
            }

            $result = $this->grant($rule, $user, $storeId, $reference, $context, [
                'note' => $this->_noteFor($event, $rule),
            ]);

            if ($result === null) {
                continue;
            }

            $results[] = $result;

            if ($firstOnly || $rule->stopProcessing) {
                break;
            }
        }

        return $results;
    }

    /**
     * The generic hook. Anything — a form plugin, a newsletter, the site's own module — calls this
     * with a handle, and every enabled custom-event rule with that handle pays out:
     *
     * ```php
     * Plugin::getInstance()->getRewards()->awardEvent($user, 'attendedWorkshop', reference: 'workshop:2026-10');
     * ```
     *
     * A rule's handle may end in `*`, so `formie:*` answers every Formie form. Pass a reference
     * when the same customer may legitimately be rewarded again for a different instance of the
     * event; leave it out and the rule pays each customer once.
     *
     * @param User|int|string $user A user, their ID, or their email address.
     * @return array<int, Transaction|Coupon>
     */
    public function awardEvent(User|int|string $user, string $handle, ?int $storeId = null, ?string $reference = null, array $context = []): array
    {
        $user = $this->resolveUser($user);
        $storeId ??= $this->_primaryStoreId();

        if ($user === null || $storeId === null) {
            return [];
        }

        return $this->awardForEvent(
            Rule::EVENT_CUSTOM,
            $user,
            $storeId,
            $reference,
            array_merge($context, ['handle' => $handle]),
            false,
            static fn(Rule $rule) => $rule->eventHandle !== null && fnmatch($rule->eventHandle, $handle),
        );
    }

    /**
     * An approved Stars review. Stars attaches a review to an entry and records the reviewer's
     * email, not a user, so the customer is found by email and "what they bought" means a product
     * they ordered that is, or is related to, the reviewed entry.
     *
     * @param object $review A `justinholtweb\stars\elements\Review`.
     * @return array<int, Transaction|Coupon>
     */
    public function awardReview(object $review, ?int $storeId = null): array
    {
        if (($review->reviewStatus ?? null) !== 'approved' || empty($review->id)) {
            return [];
        }

        $user = $this->resolveUser((string)($review->reviewerEmail ?? ''));
        $storeId ??= $this->_primaryStoreId();

        if ($user === null || $storeId === null) {
            return [];
        }

        $hasText = trim((string)($review->reviewText ?? '')) !== '';
        $entryId = isset($review->entryId) ? (int)$review->entryId : null;

        return $this->awardForEvent(
            Rule::EVENT_REVIEW,
            $user,
            $storeId,
            'stars:review:' . $review->id,
            ['review' => $review],
            false,
            function(Rule $rule) use ($hasText, $entryId, $user, $storeId) {
                if ($rule->reviewRequiresText && !$hasText) {
                    return false;
                }

                if ($rule->reviewPurchasedOnly && !$this->hasPurchased($user->id, $entryId, $storeId)) {
                    return false;
                }

                return true;
            },
        );
    }

    /**
     * Whether the customer has a completed order for something that is the entry, or is related
     * to it in either direction — a product with an entries field pointing at its review page, or
     * a review entry with a products field pointing at the product.
     */
    public function hasPurchased(int $userId, ?int $entryId, int $storeId): bool
    {
        if (!$entryId) {
            return false;
        }

        $purchasableIds = (new Query())
            ->select(['li.purchasableId'])
            ->distinct()
            ->from(['li' => CommerceTable::LINEITEMS])
            ->innerJoin(['o' => CommerceTable::ORDERS], '[[o.id]] = [[li.orderId]]')
            ->where(['o.customerId' => $userId, 'o.isCompleted' => true, 'o.storeId' => $storeId])
            ->andWhere(['not', ['li.purchasableId' => null]])
            ->column();

        if (!$purchasableIds) {
            return false;
        }

        $productIds = (new Query())
            ->select(['primaryOwnerId'])
            ->from(CommerceTable::VARIANTS)
            ->where(['id' => $purchasableIds])
            ->andWhere(['not', ['primaryOwnerId' => null]])
            ->column();

        $ids = array_values(array_unique(array_map('intval', array_merge($purchasableIds, $productIds))));

        if (in_array($entryId, $ids, true)) {
            return true;
        }

        return (new Query())
            ->from(CraftTable::RELATIONS)
            ->where(['or',
                ['sourceId' => $entryId, 'targetId' => $ids],
                ['targetId' => $entryId, 'sourceId' => $ids],
            ])
            ->exists();
    }

    /**
     * Pays out birthday rules. Run daily by `pointz/sweep/run`; safe to run more often, because
     * each customer's reward is referenced by the year and a rule pays a reference once.
     *
     * @return int How many rewards were paid.
     */
    public function runBirthdays(?DateTime $today = null): int
    {
        if (!Plugin::getInstance()->isPro() || !Plugin::commerceIsReady()) {
            return 0;
        }

        $today ??= new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
        $today = (clone $today)->setTime(0, 0);
        $count = 0;

        foreach (Commerce::getInstance()->getStores()->getAllStores() as $store) {
            $rules = Plugin::getInstance()->getRules()->getActiveRules($store->id, Rule::EVENT_BIRTHDAY);
            $byField = [];

            foreach ($rules as $rule) {
                if ($rule->birthdayField) {
                    $byField[$rule->birthdayField][] = $rule;
                }
            }

            foreach ($byField as $field => $fieldRules) {
                if (Craft::$app->getFields()->getFieldByHandle($field) === null) {
                    Craft::warning("Pointz birthday rules read the field “{$field}”, which does not exist.", 'pointz');
                    continue;
                }

                $query = User::find()->status(User::STATUS_ACTIVE)->$field(':notempty:');

                foreach ($query->each(200) as $user) {
                    /** @var User $user */
                    $birthday = $this->birthdayThisYear($user->getFieldValue($field), $today);

                    if ($birthday === null || $birthday > $today) {
                        continue;
                    }

                    if ($birthday < (clone $today)->sub(new DateInterval('P' . self::BIRTHDAY_GRACE_DAYS . 'D'))) {
                        continue;
                    }

                    foreach ($fieldRules as $rule) {
                        if (!Plugin::getInstance()->getSettings()->earningEnabled) {
                            break;
                        }

                        $result = $this->grant($rule, $user, $store->id, 'birthday:' . $birthday->format('Y'), [], [
                            'note' => $this->_noteFor(Rule::EVENT_BIRTHDAY, $rule),
                        ]);

                        if ($result !== null) {
                            $count++;

                            if ($rule->stopProcessing) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * This year's occurrence of a birthday stored in any form a date field or a text field might
     * hold. 29 February is celebrated on the 28th in a year without one.
     */
    public function birthdayThisYear(mixed $value, DateTime $today): ?DateTime
    {
        $date = $value instanceof \DateTimeInterface ? $value : DateTimeHelper::toDateTime($value, true);

        if (!$date) {
            return null;
        }

        $date = DateTime::createFromInterface($date)->setTimezone($today->getTimezone());
        $year = (int)$today->format('Y');
        $month = (int)$date->format('n');
        $day = (int)$date->format('j');

        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }

        return (clone $today)->setDate($year, $month, $day)->setTime(0, 0);
    }

    /**
     * Fires the threshold rules a customer's points balance now satisfies.
     *
     * A spending rule takes the threshold out of the balance with an ordinary ledger debit and pays
     * the reward against it; if the reward cannot be made, the debit is restored. A non-spending
     * rule fires once per customer, ever. Rules run in precedence order, so a 100-point rule above
     * a 50-point rule — with "stop after this one" — is how tiers are written.
     *
     * @return array<int, Transaction|Coupon>
     */
    public function evaluateThresholds(int $userId, int $storeId): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return [];
        }

        $rules = Plugin::getInstance()->getRules()->getActiveRules($storeId, Rule::EVENT_THRESHOLD);

        if (!$rules) {
            return [];
        }

        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null) {
            return [];
        }

        // One evaluation per account at a time. The threshold's own debit and the reward it pays
        // come back through the ledger, and a second evaluation racing this one must not see the
        // same points as unspent. Re-entry finds the lock taken and leaves.
        $mutex = Craft::$app->getMutex();
        $lock = "pointz:rewards:$storeId:$userId";

        if (!$mutex->acquire($lock, Plugin::getInstance()->getSettings()->lockTimeout)) {
            return [];
        }

        $results = [];

        try {
            foreach ($rules as $rule) {
                $fired = $rule->thresholdSpend
                    ? $this->_fireSpendingThreshold($rule, $user, $storeId)
                    : $this->_fireOnceThreshold($rule, $user, $storeId);

                array_push($results, ...$fired);

                if ($fired && $rule->stopProcessing) {
                    break;
                }
            }
        } finally {
            $mutex->release($lock);
        }

        return $results;
    }

    /**
     * A user, from a user, an ID or an email address.
     */
    public function resolveUser(User|int|string|null $user): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        if (is_int($user)) {
            return Craft::$app->getUsers()->getUserById($user);
        }

        if (is_string($user) && trim($user) !== '') {
            $user = trim($user);

            if (ctype_digit($user)) {
                return Craft::$app->getUsers()->getUserById((int)$user);
            }

            return User::find()->email($user)->status(null)->one();
        }

        return null;
    }

    /**
     * @return array<int, Transaction|Coupon>
     */
    private function _fireSpendingThreshold(Rule $rule, User $user, int $storeId): array
    {
        $results = [];
        $threshold = (float)$rule->thresholdPoints;

        if ($threshold <= 0) {
            return [];
        }

        for ($i = 0; $i < self::THRESHOLD_MAX_REPEATS; $i++) {
            if ($this->_pointsBalance($user->id, $storeId) + 0.00001 < $threshold) {
                break;
            }

            if (!$rule->matchesUser($user)) {
                break;
            }

            $event = new RewardEvent(['rule' => $rule, 'user' => $user, 'storeId' => $storeId]);

            if ($this->hasEventHandlers(self::EVENT_BEFORE_REWARD)) {
                $this->trigger(self::EVENT_BEFORE_REWARD, $event);
            }

            if (!$event->isValid) {
                break;
            }

            $ledger = Plugin::getInstance()->getLedger();

            try {
                $spend = $ledger->debit($user->id, $storeId, Rule::CURRENCY_POINTS, $threshold, [
                    'kind' => Transaction::KIND_REWARD,
                    'ruleId' => $rule->id,
                    'note' => Craft::t('pointz', 'Exchanged for: {rule}', ['rule' => $rule->name]),
                ]);
            } catch (PointzException) {
                // Somebody spent the points between the balance check and the debit.
                break;
            }

            try {
                $reward = $this->_payThreshold($rule, $user, $storeId, $spend);
            } catch (\Throwable $e) {
                $ledger->restore($spend, null, [
                    'note' => Craft::t('pointz', 'Returned: the reward could not be issued'),
                ]);
                Craft::error("Pointz could not pay threshold rule {$rule->handle} for user {$user->id}: " . $e->getMessage(), 'pointz');
                break;
            }

            $results[] = $reward;
        }

        return $results;
    }

    /**
     * @return array<int, Transaction|Coupon>
     */
    private function _fireOnceThreshold(Rule $rule, User $user, int $storeId): array
    {
        if ($this->_pointsBalance($user->id, $storeId) + 0.00001 < (float)$rule->thresholdPoints) {
            return [];
        }

        $result = $this->grant($rule, $user, $storeId, 'threshold', [], [
            'note' => $this->_noteFor(Rule::EVENT_THRESHOLD, $rule),
        ]);

        return $result === null ? [] : [$result];
    }

    /**
     * The reward a threshold's spend bought. Already past the "skip this" event, so it goes
     * straight to the coupon or the ledger rather than back through `grant()`.
     */
    private function _payThreshold(Rule $rule, User $user, int $storeId, Transaction $spend): Transaction|Coupon
    {
        $reference = 'threshold:' . $spend->id;

        if ($rule->awardsCoupon()) {
            return Plugin::getInstance()->getCoupons()->issue($rule, $user, $storeId, [
                'reference' => $reference,
                'transactionId' => $spend->id,
            ]);
        }

        $amount = Plugin::getInstance()->getEarning()->fixedAward($rule, $storeId);

        if ($amount <= 0) {
            throw new PointzException('The rule pays nothing.');
        }

        return Plugin::getInstance()->getLedger()->credit($user->id, $storeId, $rule->currency, $amount, [
            'kind' => Transaction::KIND_EARN,
            'ruleId' => $rule->id,
            'reference' => $reference,
            'dateExpires' => Plugin::getInstance()->getLifecycle()->expiryDateFor($rule->expireAfterDays),
            'note' => $this->_noteFor(Rule::EVENT_THRESHOLD, $rule),
        ]);
    }

    private function _pointsBalance(int $userId, int $storeId): float
    {
        $accounts = Plugin::getInstance()->getAccounts();
        $accounts->clearMemo($userId, $storeId);

        return $accounts->getAccount($userId, $storeId)?->pointsBalance ?? 0.0;
    }

    private function _noteFor(string $event, Rule $rule): string
    {
        return match ($event) {
            Rule::EVENT_SIGNUP => Craft::t('pointz', 'Welcome bonus: {rule}', ['rule' => $rule->name]),
            Rule::EVENT_REVIEW => Craft::t('pointz', 'Review reward: {rule}', ['rule' => $rule->name]),
            Rule::EVENT_BIRTHDAY => Craft::t('pointz', 'Birthday reward: {rule}', ['rule' => $rule->name]),
            default => $rule->name,
        };
    }

    private function _primaryStoreId(): ?int
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getStores()->getPrimaryStore()?->id;
    }
}
