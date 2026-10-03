<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\helpers\Currency;
use craft\commerce\models\OrderNotice;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\adjusters\Redemption as RedemptionAdjuster;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Quote;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Settings;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * Spending points and store credit at checkout.
 *
 * The customer's request is stored as **intent** against the cart and re-clamped on every
 * recalculation. Nothing is spent until the order completes: a cart that sits for a week while
 * the balance changes underneath it simply quotes a smaller discount, and an abandoned cart costs
 * the customer nothing.
 */
class Redemption extends Component
{
    /**
     * Reads the cart's intent.
     *
     * @return array{points: float, credit: float, userId: int|null}
     */
    public function getIntent(int $orderId): array
    {
        $row = (new Query())
            ->select(['points', 'credit', 'userId'])
            ->from(Table::CART_REDEMPTIONS)
            ->where(['orderId' => $orderId])
            ->one();

        return [
            'points' => (float)($row['points'] ?? 0),
            'credit' => (float)($row['credit'] ?? 0),
            'userId' => isset($row['userId']) ? (int)$row['userId'] : null,
        ];
    }

    /**
     * Whose balance this order may spend, or null if nobody's.
     *
     * Not simply the order's customer. Commerce makes whoever owns an email the customer of a
     * guest cart that types it in, so before 5.0.1 a guest could put a registered customer's email
     * on their cart and spend that customer's points and credit — and see their balances. The
     * customer counts only when they are the one signed in, or when they were signed in and asked
     * for the redemption themselves (which is what a recalculation in a queue job or a gateway
     * webhook, with nobody signed in, has to go on).
     */
    public function spenderId(Order $order): ?int
    {
        $customerId = $order->getCustomerId();

        if ($customerId === null) {
            return null;
        }

        if ($order->id && $this->getIntent($order->id)['userId'] === $customerId) {
            return $customerId;
        }

        $identity = Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getUser()->getIdentity();

        return $identity?->id === $customerId ? $customerId : null;
    }

    /**
     * Records what the customer wants to spend, and answers with what they will actually get.
     *
     * The stored figure is the *request*, not the clamp — a customer who asks for 500 points on a
     * small cart and then adds another item should get all 500, not the 200 the small cart could
     * take.
     */
    public function setIntent(Order $order, ?float $points = null, ?float $credit = null, ?int $userId = null): Quote
    {
        $current = $this->getIntent($order->id);
        // The customer the request is on behalf of — the controller only lets the cart's own,
        // signed-in customer through. Nobody's intent spends anything (see spenderId()).
        $userId ??= Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getUser()->getIdentity()?->id;
        $points = $points === null ? $current['points'] : max(0, $points);
        $credit = $credit === null ? $current['credit'] : max(0, $credit);

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        Craft::$app->getDb()->createCommand()->upsert(Table::CART_REDEMPTIONS, [
            'orderId' => $order->id,
            'userId' => $userId,
            'points' => $points,
            'credit' => $credit,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], array_filter([
            // Without a user, the owner already recorded stands.
            'userId' => $userId,
            'points' => $points,
            'credit' => $credit,
            'dateUpdated' => $now,
        ], static fn($value, $key) => $key !== 'userId' || $value !== null, ARRAY_FILTER_USE_BOTH))->execute();

        $order->recalculate();

        return $this->quote($order);
    }

    public function clearIntent(int $orderId): void
    {
        Db::delete(Table::CART_REDEMPTIONS, ['orderId' => $orderId]);
    }

    /**
     * What the order's redemption comes to, given the balance and every cap.
     *
     * Pass explicit amounts to quote a hypothetical; pass nothing to quote the cart's own intent.
     */
    public function quote(Order $order, ?float $points = null, ?float $credit = null): Quote
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();
        $quote = new Quote();

        $intent = $order->id ? $this->getIntent($order->id) : ['points' => 0, 'credit' => 0, 'userId' => null];
        $quote->requestedPoints = $points ?? $intent['points'];
        $quote->requestedCredit = $credit ?? $intent['credit'];

        $storeId = $order->getStore()->id;
        // Balances and everything spendable come from the spender, never the bare customer: a
        // quote is public (the anonymous quote action, the Twig variable) and so is a cart's email.
        $userId = $this->spenderId($order);
        $account = $userId ? $plugin->getAccounts()->getAccount($userId, $storeId) : null;

        $quote->pointsBalance = $account->pointsBalance ?? 0;
        $quote->creditBalance = $account->creditBalance ?? 0;

        $quote->base = $this->redeemableBase($order);
        $quote->cap = $settings->maxRedemptionPercent === null
            ? $quote->base
            : $this->_money($quote->base * $settings->maxRedemptionPercent / 100, $storeId);

        // Nothing may take the order below zero, whatever the caps say.
        $headroom = max(0, $this->orderHeadroom($order));
        $quote->cap = min($quote->cap, $headroom);

        $quote->maxPoints = $this->_blocks(min(
            $quote->pointsBalance,
            $this->pointsForValue($quote->cap)
        ), $settings);

        if (!$settings->redemptionEnabled || $userId === null) {
            $quote->maxPoints = 0;
        }

        $wanted = min($quote->requestedPoints, $quote->maxPoints);
        $wanted = $this->_blocks($wanted, $settings);

        if ($wanted > 0 && $wanted < $settings->minPointsToRedeem) {
            $quote->notices[] = Craft::t('pointz', 'At least {min} {label} are needed to redeem.', [
                'min' => $this->format($settings->minPointsToRedeem),
                'label' => $settings->pointsLabelPlural,
            ]);
            $wanted = 0;
        }

        if ($quote->requestedPoints > 0 && $wanted < $quote->requestedPoints) {
            $quote->notices[] = $this->_clampNotice($quote, $wanted, $settings);
        }

        $quote->points = $wanted;
        $quote->pointsValue = $this->_money($this->valueForPoints($wanted), $storeId);

        // Credit is the customer's own money rather than a promotional discount, so the
        // percentage cap does not apply to it — only the order's remaining value does.
        $creditRoom = max(0, $this->_money($headroom - $quote->pointsValue, $storeId));
        $quote->maxCredit = $plugin->isPro() && $settings->creditRedemptionEnabled
            ? min($quote->creditBalance, $creditRoom)
            : 0;
        $quote->credit = $this->_money(min($quote->requestedCredit, $quote->maxCredit), $storeId);

        if ($quote->requestedCredit > 0 && $quote->credit < $quote->requestedCredit) {
            $quote->notices[] = Craft::t('pointz', 'Only {amount} of {label} could be applied to this order.', [
                'amount' => $this->formatMoney($quote->credit, $storeId),
                'label' => $settings->creditLabel,
            ]);
        }

        return $quote;
    }

    /**
     * The figure a redemption is measured against.
     *
     * Read from the adjustments Commerce has already computed this pass — our own adjuster runs
     * last, and `recalculate()` clears every adjustment before the run, so this can never see a
     * previous redemption and shrink itself a little more on every pass.
     */
    public function redeemableBase(Order $order): float
    {
        $settings = Plugin::getInstance()->getSettings();

        $amount = match ($settings->redeemableBase) {
            Settings::BASE_TOTAL => $order->getTotal(),
            Settings::BASE_TOTAL_LESS_SHIPPING => $order->getTotal() - $order->getTotalShippingCost(),
            Settings::BASE_TOTAL_LESS_SHIPPING_TAX => $order->getTotal() - $order->getTotalShippingCost() - $order->getTotalTax(),
            default => $order->getItemSubtotal(),
        };

        return max(0, round($amount - $this->_ownAdjustmentsTotal($order), 5));
    }

    /**
     * How much money is left on the order to discount at all.
     */
    public function orderHeadroom(Order $order): float
    {
        return max(0, round($order->getTotal() - $this->_ownAdjustmentsTotal($order), 5));
    }

    /**
     * Points to money.
     */
    public function valueForPoints(float $points): float
    {
        $rate = Plugin::getInstance()->getSettings()->pointsPerUnit;

        return $rate > 0 ? $points / $rate : 0.0;
    }

    /**
     * Money to points.
     */
    public function pointsForValue(float $value): float
    {
        return $value * Plugin::getInstance()->getSettings()->pointsPerUnit;
    }

    /**
     * Turns the adjustments on a completing order into real debits.
     *
     * The adjustments are the source of truth here, not the intent: whatever the customer is
     * actually being charged is what the ledger must move. The points count travels in the
     * adjustment's snapshot so a rate change between cart and checkout cannot alter what is spent.
     *
     * @return Transaction[]
     * @throws PointzException when the balance falls short and the store has chosen to fail.
     */
    public function commitOrder(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $userId = $this->spenderId($order);

        if ($userId === null) {
            // The adjuster only discounts for a spender, so redemption adjustments here mean the
            // customer changed after they were made. Spending someone else's balance is never the
            // answer; the order is treated like a shortfall instead.
            if ($order->getCustomerId() !== null && $this->_hasRedemptionAdjustments($order)) {
                $message = 'Pointz did not debit order ' . $order->id . ': its redemption was not made by its customer.';

                if ($settings->onShortfall === Settings::SHORTFALL_THROW) {
                    throw new PointzException($message);
                }

                $order->addNotice(Craft::createObject([
                    'class' => OrderNotice::class,
                    'attributes' => [
                        'type' => 'pointzShortfall',
                        'attribute' => 'total',
                        'message' => Craft::t('pointz', 'The balance did not cover the whole redemption on this order.'),
                    ],
                ]));
                Craft::warning($message, 'pointz');
            }

            return [];
        }

        $ledger = Plugin::getInstance()->getLedger();
        $storeId = $order->getStore()->id;
        $written = [];

        // Nothing may be spent twice: a completion retried in the same request, or a status
        // handler that fires again, both find the debit already in the ledger.
        if ($ledger->getTransactionsQuery(['orderId' => $order->id, 'kind' => Transaction::KIND_REDEEM])->exists()) {
            return [];
        }

        foreach ($order->getAdjustments() as $adjustment) {
            if (!in_array($adjustment->type, RedemptionAdjuster::adjustmentTypes(), true)) {
                continue;
            }

            $snapshot = $adjustment->sourceSnapshot ?: [];
            $isPoints = $adjustment->type === RedemptionAdjuster::TYPE_POINTS;
            $currency = $isPoints ? Rule::CURRENCY_POINTS : Rule::CURRENCY_CREDIT;
            $amount = $isPoints
                ? (float)($snapshot['points'] ?? 0)
                : abs((float)$adjustment->amount);

            if ($amount <= 0) {
                continue;
            }

            try {
                $written[] = $ledger->debit($userId, $storeId, $currency, $amount, [
                    'kind' => Transaction::KIND_REDEEM,
                    'orderId' => $order->id,
                    'allowPartial' => $settings->onShortfall === Settings::SHORTFALL_CLAMP,
                    'note' => Craft::t('pointz', 'Redeemed at checkout'),
                ]);
            } catch (PointzException $e) {
                if ($settings->onShortfall === Settings::SHORTFALL_THROW) {
                    throw $e;
                }

                // Clamp mode: the customer keeps the discount, the store absorbs the difference,
                // and the order says so rather than the books quietly disagreeing.
                $order->addNotice(Craft::createObject([
                    'class' => OrderNotice::class,
                    'attributes' => [
                        'type' => 'pointzShortfall',
                        'attribute' => 'total',
                        'message' => Craft::t('pointz', 'The balance did not cover the whole redemption on this order.'),
                    ],
                ]));

                Craft::warning(sprintf(
                    'Pointz could not fully debit order %s: %s',
                    $order->id,
                    $e->getMessage()
                ), 'pointz');
            }
        }

        if ($written) {
            $this->clearIntent($order->id);
        }

        return $written;
    }

    /**
     * Hands back what an order spent — the other half of a refund.
     *
     * @return Transaction[]
     */
    public function returnRedeemed(Order $order, float $ratio = 1.0, ?int $authorId = null): array
    {
        $ledger = Plugin::getInstance()->getLedger();
        $returned = [];

        foreach ($ledger->getTransactionsForOrder($order->id, Transaction::KIND_REDEEM) as $spend) {
            $outstanding = $ledger->getUnrestoredAmount($spend);

            if ($outstanding <= 0) {
                continue;
            }

            $share = $ratio >= 1 ? $outstanding : min($outstanding, round(abs($spend->amount) * $ratio, 5));

            if ($share <= 0) {
                continue;
            }

            $transaction = $ledger->restore($spend, $share, [
                'orderId' => $order->id,
                'authorId' => $authorId,
                'note' => Craft::t('pointz', 'Returned after a refund'),
            ]);

            if ($transaction !== null) {
                $returned[] = $transaction;
            }
        }

        return $returned;
    }

    /**
     * The points a completed order spent, for the order panel.
     */
    public function getSpentOnOrder(int $orderId, string $currency = Rule::CURRENCY_POINTS): float
    {
        $spent = 0.0;

        foreach (Plugin::getInstance()->getLedger()->getTransactionsForOrder($orderId, Transaction::KIND_REDEEM) as $transaction) {
            if ($transaction->currency === $currency) {
                $spent += abs($transaction->amount);
            }
        }

        return round($spent, 5);
    }

    /**
     * A number of points as the store writes them — whole where they are whole, and with the
     * store's own word for them left to the caller.
     */
    public function format(float $points): string
    {
        if (abs($points - round($points)) < 0.00001) {
            return Craft::$app->getFormatter()->asDecimal($points, 0);
        }

        return Craft::$app->getFormatter()->asDecimal($points, 2);
    }

    public function formatMoney(float $amount, ?int $storeId = null): string
    {
        if (!Plugin::commerceIsReady()) {
            return (string)$amount;
        }

        $store = $storeId
            ? Commerce::getInstance()->getStores()->getStoreById($storeId)
            : Commerce::getInstance()->getStores()->getPrimaryStore();

        return Craft::$app->getFormatter()->asCurrency($amount, $store?->getCurrency()?->getCode());
    }

    /**
     * Rounds a points figure down to the store's block size, so "redeem in hundreds" means it.
     */
    private function _hasRedemptionAdjustments(Order $order): bool
    {
        foreach ($order->getAdjustments() as $adjustment) {
            if (in_array($adjustment->type, RedemptionAdjuster::adjustmentTypes(), true)) {
                return true;
            }
        }

        return false;
    }

    private function _blocks(float $points, Settings $settings): float
    {
        $block = $settings->redeemBlockSize;

        if ($block <= 0) {
            return max(0, $points);
        }

        return max(0, floor(($points + 0.00001) / $block) * $block);
    }

    private function _money(float $amount, ?int $storeId): float
    {
        if (!Plugin::commerceIsReady()) {
            return round($amount, 2);
        }

        $store = $storeId ? Commerce::getInstance()->getStores()->getStoreById($storeId) : null;

        return Currency::round($amount, $store?->getCurrency());
    }

    /**
     * Pointz's own adjustments already on the order. During a recalculation this is always zero —
     * Commerce clears the adjustments first — but a completed order still carries them, and the
     * order panel asks the same questions of it.
     */
    private function _ownAdjustmentsTotal(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getAdjustments() as $adjustment) {
            if (in_array($adjustment->type, RedemptionAdjuster::adjustmentTypes(), true)) {
                $total += $adjustment->amount;
            }
        }

        return $total;
    }

    private function _clampNotice(Quote $quote, float $allowed, Settings $settings): string
    {
        if ($quote->requestedPoints > $quote->pointsBalance) {
            return Craft::t('pointz', 'You have {balance} {label} to spend.', [
                'balance' => $this->format($quote->pointsBalance),
                'label' => $settings->pointsLabelPlural,
            ]);
        }

        return Craft::t('pointz', 'This order can take {max} {label}.', [
            'max' => $this->format($allowed),
            'label' => $settings->pointsLabelPlural,
        ]);
    }
}
