<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\helpers\Currency;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\adjusters\Redemption;
use justinholtweb\pointz\events\AwardEvent;
use justinholtweb\pointz\models\Award;
use justinholtweb\pointz\models\AwardLine;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * The rule engine: what an order is worth.
 *
 * `evaluateOrder()` decides; `awardOrder()` writes what it decided. A product-page preview, the
 * cart's "you'll earn" line and the real accrual at checkout all call `evaluateOrder()`, so a
 * promise made on the front end is arithmetically the same promise kept at the ledger.
 */
class Earning extends Component
{
    /**
     * @event AwardEvent Raised after the rules have produced an award and before anything is
     *                   written. The place to add what the rule builder cannot express.
     */
    public const EVENT_BEFORE_AWARD = 'beforeAward';

    /**
     * Works out what an order earns, without writing anything.
     */
    public function evaluateOrder(Order $order, bool $isPreview = false): Award
    {
        $award = new Award();
        $settings = Plugin::getInstance()->getSettings();
        $storeId = $order->getStore()->id;
        $user = $this->getCustomer($order);
        $rules = Plugin::getInstance()->getRules()->getActiveRules($storeId, Rule::EVENT_ORDER);

        if (!$rules) {
            $award->notices[] = Craft::t('pointz', 'No earning rule is switched on for this store.');
        }

        foreach ($rules as $rule) {
            if (!$rule->matchesUser($user)) {
                continue;
            }

            if ($rule->firstOrderOnly && !$this->_isFirstOrder($order, $user)) {
                continue;
            }

            if (!$rule->matchesOrder($order)) {
                continue;
            }

            $lines = $rule->scope === Rule::SCOPE_LINE_ITEM
                ? $this->_lineItemLines($rule, $order, $settings)
                : $this->_orderLines($rule, $order, $settings);

            foreach ($this->_applyCaps($rule, $lines, $user) as $line) {
                if ($line->amount > 0) {
                    $award->addLine($line);
                }
            }

            if ($rule->stopProcessing) {
                break;
            }
        }

        $event = new AwardEvent([
            'award' => $award,
            'order' => $order,
            'user' => $user,
            'isPreview' => $isPreview,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_AWARD)) {
            $this->trigger(self::EVENT_BEFORE_AWARD, $event);
        }

        return $event->award;
    }

    /**
     * Writes what an order earned. Idempotent: an order that has already earned is left alone, so
     * a status change firing twice — or a completion retried after a payment hiccup — cannot pay
     * the customer twice.
     *
     * @return Transaction[]
     */
    public function awardOrder(Order $order, ?string $batchId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->earningEnabled) {
            return [];
        }

        if (!$order->isCompleted) {
            return [];
        }

        if ($this->hasEarned($order)) {
            return [];
        }

        $user = $this->getCustomer($order);

        if ($user === null) {
            return [];
        }

        if (!$settings->earnAsGuest && !$user->active) {
            return [];
        }

        $award = $this->evaluateOrder($order);

        if ($award->getIsEmpty()) {
            return [];
        }

        $ledger = Plugin::getInstance()->getLedger();
        $lifecycle = Plugin::getInstance()->getLifecycle();
        $storeId = $order->getStore()->id;
        $pending = $settings->holdDays > 0;
        $dateAvailable = $pending ? $lifecycle->holdUntil() : null;
        $written = [];

        foreach ($award->lines as $line) {
            $written[] = $ledger->credit($user->id, $storeId, $line->currency, $line->amount, [
                'kind' => Transaction::KIND_EARN,
                'orderId' => $order->id,
                'ruleId' => $line->ruleId,
                'batchId' => $batchId,
                'pending' => $pending,
                'dateAvailable' => $dateAvailable,
                'dateExpires' => $lifecycle->expiryDateFor($line->expireAfterDays),
                'note' => $this->_noteFor($line),
            ]);
        }

        return $written;
    }

    /**
     * Whether an order has already had its earnings written.
     */
    public function hasEarned(Order $order): bool
    {
        if (!$order->id) {
            return false;
        }

        return (bool)Plugin::getInstance()->getLedger()
            ->getTransactionsQuery(['orderId' => $order->id, 'kind' => Transaction::KIND_EARN])
            ->exists();
    }

    /**
     * The signup bonus, if a rule offers one. Returns null when nothing applied — including when
     * the customer has already had it, which the ledger's own history answers.
     */
    public function awardSignup(User $user, ?int $storeId = null): ?Transaction
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->earningEnabled || !Plugin::commerceIsReady()) {
            return null;
        }

        $storeId ??= Commerce::getInstance()->getStores()->getPrimaryStore()?->id;

        if (!$storeId) {
            return null;
        }

        $rules = Plugin::getInstance()->getRules()->getActiveRules($storeId, Rule::EVENT_SIGNUP);
        $ledger = Plugin::getInstance()->getLedger();
        $lifecycle = Plugin::getInstance()->getLifecycle();

        foreach ($rules as $rule) {
            if (!$rule->matchesUser($user)) {
                continue;
            }

            // One signup bonus per rule per customer, forever — the rule's own ledger history is
            // the guard, so re-saving a user or reactivating an account cannot pay twice.
            $already = $ledger->getTransactionsQuery([
                'userId' => $user->id,
                'ruleId' => $rule->id,
            ])->exists();

            if ($already) {
                continue;
            }

            $amount = $this->_round($rule, $rule->rate * $rule->multiplier);

            if ($amount <= 0) {
                continue;
            }

            return $ledger->credit($user->id, $storeId, $rule->currency, $amount, [
                'kind' => Transaction::KIND_EARN,
                'ruleId' => $rule->id,
                'dateExpires' => $lifecycle->expiryDateFor($rule->expireAfterDays),
                'note' => Craft::t('pointz', 'Welcome bonus: {rule}', ['rule' => $rule->name]),
            ]);
        }

        return null;
    }

    /**
     * What a single purchasable is worth, for a "you'll earn" line on a product page.
     *
     * Only the rules that can be evaluated without an order take part — a line-item rule always
     * can, an order rule can when its basis is the item subtotal. A rule whose basis is the order
     * total genuinely cannot be answered for one product, and saying so beats guessing.
     */
    public function previewPurchasable(mixed $purchasable, float $qty = 1, ?int $storeId = null, ?User $user = null): Award
    {
        $award = new Award();

        if (!Plugin::commerceIsReady()) {
            return $award;
        }

        $storeId ??= Commerce::getInstance()->getStores()->getPrimaryStore()?->id;

        if (!$storeId) {
            return $award;
        }

        $settings = Plugin::getInstance()->getSettings();
        $price = (float)($purchasable->getPrice() ?? 0);
        $subtotal = $price * $qty;

        foreach (Plugin::getInstance()->getRules()->getActiveRules($storeId, Rule::EVENT_ORDER) as $rule) {
            if ($user !== null && !$rule->matchesUser($user)) {
                continue;
            }

            if ($rule->scope === Rule::SCOPE_LINE_ITEM) {
                if (!$rule->matchesPurchasable($purchasable)) {
                    continue;
                }

                $basis = $rule->basis === Rule::BASIS_LINE_ITEM_QTY ? $qty : $subtotal;
            } elseif ($rule->basis === Rule::BASIS_ITEM_SUBTOTAL || $rule->calculation === Rule::CALC_FIXED) {
                $basis = $subtotal;
            } else {
                $award->notices[] = Craft::t('pointz', '“{rule}” depends on the whole order, so it is not counted here.', [
                    'rule' => $rule->name,
                ]);
                continue;
            }

            $amount = $this->_amountFor($rule, $basis, $storeId);

            if ($amount <= 0) {
                continue;
            }

            $line = new AwardLine([
                'ruleId' => $rule->id,
                'ruleName' => $rule->name,
                'currency' => $rule->currency,
                'amount' => $amount,
                'basis' => $basis,
                'expireAfterDays' => $rule->expireAfterDays,
            ]);

            $award->addLine($line);

            if ($rule->stopProcessing) {
                break;
            }
        }

        return $award;
    }

    /**
     * The customer behind an order. Commerce gives every order a user, guest checkouts included,
     * so this is rarely null — but an order saved before a customer was attached still is.
     */
    public function getCustomer(Order $order): ?User
    {
        $customerId = $order->getCustomerId();

        return $customerId ? Craft::$app->getUsers()->getUserById($customerId) : null;
    }

    /**
     * @return AwardLine[]
     */
    private function _orderLines(Rule $rule, Order $order, $settings): array
    {
        $basis = $this->basisAmount($rule->basis, $order, $settings->earnOnRedeemedValue);
        $amount = $this->_amountFor($rule, $basis, $order->getStore()->id);

        // A line is produced even when the arithmetic comes to zero: the rule *matched*, and a
        // rule with a minimum award is entitled to raise it. Zero lines that no floor rescues are
        // dropped after the caps run.
        return [
            new AwardLine([
                'ruleId' => $rule->id,
                'ruleName' => $rule->name,
                'currency' => $rule->currency,
                'amount' => $amount,
                'basis' => $basis,
                'expireAfterDays' => $rule->expireAfterDays,
            ]),
        ];
    }

    /**
     * @return AwardLine[]
     */
    private function _lineItemLines(Rule $rule, Order $order, $settings): array
    {
        $lines = [];
        $storeId = $order->getStore()->id;

        foreach ($order->getLineItems() as $lineItem) {
            if (!$rule->matchesPurchasable($lineItem->getPurchasable())) {
                continue;
            }

            $basis = $rule->basis === Rule::BASIS_LINE_ITEM_QTY
                ? (float)$lineItem->qty
                : (float)$lineItem->getSubtotal();

            $amount = $this->_amountFor($rule, $basis, $storeId);

            $lines[] = new AwardLine([
                'ruleId' => $rule->id,
                'ruleName' => $rule->name,
                'currency' => $rule->currency,
                'amount' => $amount,
                'basis' => $basis,
                'lineItemId' => $lineItem->id,
                'lineItemDescription' => $lineItem->getDescription(),
                'expireAfterDays' => $rule->expireAfterDays,
            ]);
        }

        return $lines;
    }

    /**
     * The monetary figure a rate is applied to.
     *
     * Redemption is subtracted unless the store has opted into earning on it: points that buy an
     * order and then earn points on the same money are a loop the store pays for twice.
     */
    public function basisAmount(string $basis, Order $order, bool $includeRedeemed = false): float
    {
        $amount = match ($basis) {
            Rule::BASIS_TOTAL => $order->getTotal(),
            Rule::BASIS_TOTAL_LESS_SHIPPING => $order->getTotal() - $order->getTotalShippingCost(),
            Rule::BASIS_TOTAL_LESS_SHIPPING_TAX => $order->getTotal() - $order->getTotalShippingCost() - $order->getTotalTax(),
            default => $order->getItemSubtotal(),
        };

        if (!$includeRedeemed) {
            $amount -= $this->redeemedValue($order, $basis);
        }

        return max(0, round($amount, 5));
    }

    /**
     * How much of the order was paid for with points or credit.
     *
     * The total bases already have the redemption in them — the adjustments are negative — so
     * subtracting it again would double-count. Only the item subtotal, which ignores adjustments
     * entirely, needs the correction.
     */
    public function redeemedValue(Order $order, string $basis): float
    {
        if ($basis !== Rule::BASIS_ITEM_SUBTOTAL) {
            return 0.0;
        }

        $redeemed = 0.0;

        foreach ($order->getAdjustments() as $adjustment) {
            if (in_array($adjustment->type, Redemption::adjustmentTypes(), true)) {
                $redeemed += abs($adjustment->amount);
            }
        }

        return $redeemed;
    }

    /**
     * Applies a rule's floor, ceiling and per-customer cap. Order-scope rules have one line;
     * line-item rules share the cap across their lines in order, so the cap is a cap on the rule
     * rather than on each item it happens to match.
     *
     * @param AwardLine[] $lines
     * @return AwardLine[]
     */
    private function _applyCaps(Rule $rule, array $lines, ?User $user): array
    {
        if (!$lines) {
            return [];
        }

        $total = array_sum(array_map(static fn(AwardLine $line) => $line->amount, $lines));
        $capped = $total;
        $note = null;

        if ($rule->minAward !== null && $capped < $rule->minAward) {
            $capped = $rule->minAward;
            $note = Craft::t('pointz', 'Raised to the rule’s minimum.');
        }

        if ($rule->maxAward !== null && $capped > $rule->maxAward) {
            $capped = $rule->maxAward;
            $note = Craft::t('pointz', 'Trimmed to the rule’s maximum.');
        }

        if ($rule->maxPerUser !== null && $user !== null) {
            $already = Plugin::getInstance()->getRules()->getAwardedInPeriod($rule, $user->id);
            $headroom = max(0, $rule->maxPerUser - $already);

            if ($capped > $headroom) {
                $capped = $headroom;
                $note = Craft::t('pointz', 'Trimmed to what is left of this customer’s cap.');
            }
        }

        if ($capped >= $total && $note === null) {
            return $lines;
        }

        // Spread the capped total back over the lines in proportion, so a per-item breakdown
        // still adds up to what was actually awarded.
        $scale = $total > 0 ? $capped / $total : 0;
        $running = 0.0;
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $line->amount = $index === $last
                ? round($capped - $running, 5)
                : $this->_round($rule, $line->amount * $scale);
            $running += $line->amount;
            $line->note = $note;
        }

        return $lines;
    }

    private function _amountFor(Rule $rule, float $basis, ?int $storeId): float
    {
        $raw = $rule->calculation === Rule::CALC_FIXED
            ? $rule->rate * $rule->multiplier
            : $basis * $rule->rate * $rule->multiplier;

        if ($rule->currency === Rule::CURRENCY_CREDIT) {
            return max(0, $this->_roundMoney($raw, $storeId));
        }

        return max(0, $this->_round($rule, $raw));
    }

    /**
     * Points are whole things. A rule that awards 2.5 points has to decide which way it goes, and
     * "down" is the default because a customer never complains about the number they were shown.
     */
    private function _round(Rule $rule, float $amount): float
    {
        return match ($rule->rounding) {
            Rule::ROUND_UP => (float)ceil($amount),
            Rule::ROUND_NEAREST => (float)round($amount),
            default => (float)floor($amount),
        };
    }

    /**
     * Credit is money, so it rounds to the store's currency rather than to a whole unit.
     */
    private function _roundMoney(float $amount, ?int $storeId): float
    {
        if (!Plugin::commerceIsReady()) {
            return round($amount, 2);
        }

        $store = $storeId ? Commerce::getInstance()->getStores()->getStoreById($storeId) : null;
        $currency = $store?->getCurrency();

        return Currency::round($amount, $currency);
    }

    private function _noteFor(AwardLine $line): string
    {
        if ($line->lineItemDescription) {
            return Craft::t('pointz', '{rule} — {item}', [
                'rule' => $line->ruleName,
                'item' => $line->lineItemDescription,
            ]);
        }

        return $line->ruleName;
    }

    /**
     * Whether this is the customer's first completed order. The order being awarded may or may
     * not already be saved, so it is excluded by ID rather than by counting to one.
     */
    private function _isFirstOrder(Order $order, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $query = Order::find()
            ->customerId($user->id)
            ->isCompleted(true)
            ->storeId($order->getStore()->id)
            ->status(null)
            ->limit(1);

        if ($order->id) {
            $query->andWhere(['not', ['commerce_orders.id' => $order->id]]);
        }

        return !$query->exists();
    }

    /**
     * @param LineItem $lineItem
     */
    private function _lineItemLabel(LineItem $lineItem): string
    {
        return $lineItem->getDescription() ?: (string)$lineItem->id;
    }

    /**
     * Now, in the site's time zone. Kept in one place so the checks can reason about windows.
     */
    public function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }
}
