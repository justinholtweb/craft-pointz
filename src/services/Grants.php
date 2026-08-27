<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * Value moved by a person rather than by a rule: goodwill gestures, corrections, refunds paid as
 * store credit, and bulk grants from the console.
 *
 * Everything here goes through `Ledger`, so a manual grant expires, gets spent and reverses
 * exactly like an earned one. There is no such thing as a balance that was typed in.
 */
class Grants extends Component
{
    /**
     * Hands a customer value.
     */
    public function grant(
        int $userId,
        int $storeId,
        string $currency,
        float $amount,
        ?string $note = null,
        ?int $authorId = null,
        ?int $expireAfterDays = null,
    ): Transaction {
        $this->_assertCurrencyAllowed($currency);

        return Plugin::getInstance()->getLedger()->credit($userId, $storeId, $currency, $amount, [
            'kind' => Transaction::KIND_ADJUST,
            'authorId' => $authorId ?? Craft::$app->getUser()->getIdentity()?->id,
            'note' => $note,
            'dateExpires' => Plugin::getInstance()->getLifecycle()->expiryDateFor($expireAfterDays),
        ]);
    }

    /**
     * Takes value away.
     *
     * A deduction consumes lots like any other spend, so it takes the soonest-expiring value
     * first — which is what a customer would choose if they were asked.
     */
    public function deduct(
        int $userId,
        int $storeId,
        string $currency,
        float $amount,
        ?string $note = null,
        ?int $authorId = null,
        bool $allowPartial = false,
    ): Transaction {
        $this->_assertCurrencyAllowed($currency);

        return Plugin::getInstance()->getLedger()->debit($userId, $storeId, $currency, $amount, [
            'kind' => Transaction::KIND_ADJUST,
            'authorId' => $authorId ?? Craft::$app->getUser()->getIdentity()?->id,
            'note' => $note,
            'allowPartial' => $allowPartial,
        ]);
    }

    /**
     * Pays a refund as store credit instead of money back.
     *
     * Commerce is not told anything: this is a credit note the shop chooses to issue, and the
     * gateway refund — if there is one — is a separate decision made in Commerce's own screens.
     * Doing it the other way round would let a mis-click both refund the card and issue credit.
     */
    public function refundToCredit(Order $order, float $amount, ?string $note = null, ?int $authorId = null): Transaction
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new PointzException('Store credit needs Pointz Pro.');
        }

        $userId = $order->getCustomerId();

        if ($userId === null) {
            throw new PointzException('That order has no customer to credit.');
        }

        return Plugin::getInstance()->getLedger()->credit(
            $userId,
            $order->getStore()->id,
            Rule::CURRENCY_CREDIT,
            $amount,
            [
                'kind' => Transaction::KIND_ADJUST,
                'orderId' => $order->id,
                'authorId' => $authorId ?? Craft::$app->getUser()->getIdentity()?->id,
                'reference' => $order->reference ?: $order->getShortNumber(),
                'note' => $note ?? Craft::t('pointz', 'Refunded as store credit'),
                'dateExpires' => Plugin::getInstance()->getLifecycle()->expiryDateFor(null),
            ]
        );
    }

    /**
     * Grants the same amount to many customers under one batch ID, so the whole run can be found
     * again — and reversed — as a unit.
     *
     * @param int[] $userIds
     * @return array{batchId: string, granted: int, failed: array<int, string>}
     */
    public function grantMany(
        array $userIds,
        int $storeId,
        string $currency,
        float $amount,
        ?string $note = null,
        ?int $authorId = null,
    ): array {
        $this->_assertCurrencyAllowed($currency);

        $batchId = StringHelper::UUID();
        $ledger = Plugin::getInstance()->getLedger();
        $lifecycle = Plugin::getInstance()->getLifecycle();
        $granted = 0;
        $failed = [];

        foreach ($userIds as $userId) {
            try {
                $ledger->credit($userId, $storeId, $currency, $amount, [
                    'kind' => Transaction::KIND_ADJUST,
                    'authorId' => $authorId,
                    'batchId' => $batchId,
                    'note' => $note,
                    'dateExpires' => $lifecycle->expiryDateFor(null),
                ]);
                $granted++;
            } catch (\Throwable $e) {
                $failed[$userId] = $e->getMessage();
            }
        }

        return ['batchId' => $batchId, 'granted' => $granted, 'failed' => $failed];
    }

    /**
     * Undoes a batch by taking back whatever is left of what it gave.
     *
     * @return array{reversed: int, short: int}
     */
    public function reverseBatch(string $batchId, ?int $authorId = null): array
    {
        $plugin = Plugin::getInstance();
        $ledger = $plugin->getLedger();
        $reversed = 0;
        $short = 0;

        foreach ($ledger->getTransactionsQuery(['batchId' => $batchId])->all() as $row) {
            $transaction = new Transaction($row);

            if ($transaction->amount <= 0) {
                continue;
            }

            $lots = array_values(array_filter(
                $ledger->getSpendableLots($transaction->userId, $transaction->storeId, $transaction->currency),
                static fn($lot) => $lot->transactionId === $transaction->id
            ));

            $outcome = $ledger->revoke($lots, $transaction->amount, [
                'authorId' => $authorId,
                'batchId' => $batchId,
                'note' => Craft::t('pointz', 'Batch reversed'),
            ]);

            if ($outcome['taken'] > 0) {
                $reversed++;
            }

            if ($outcome['short'] > 0) {
                $short++;
            }
        }

        return ['reversed' => $reversed, 'short' => $short];
    }

    private function _assertCurrencyAllowed(string $currency): void
    {
        if ($currency === Rule::CURRENCY_CREDIT && !Plugin::getInstance()->isPro()) {
            throw new PointzException('Store credit needs Pointz Pro.');
        }
    }
}
