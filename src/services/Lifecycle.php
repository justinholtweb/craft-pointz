<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\models\Lot;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Settings;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * What happens to value over time: holds clearing, expiry, and refunds taking it back.
 *
 * Every sweep here is idempotent and safe to run on a schedule. None of them writes a balance —
 * they move lots and let `Accounts::refresh()` do the arithmetic, which is why running one twice
 * cannot double anything.
 */
class Lifecycle extends Component
{
    /**
     * When a lot earned now would expire, honouring a rule's own override.
     */
    public function expiryDateFor(?int $ruleDays): ?DateTime
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->expiryEnabled) {
            return null;
        }

        $days = $ruleDays ?? $settings->expireAfterDays;

        if ($days === null || $days < 1) {
            return null;
        }

        return $this->now()->add(new DateInterval("P{$days}D"));
    }

    /**
     * When a lot earned now stops being pending, or null when nothing is held.
     */
    public function holdUntil(): ?DateTime
    {
        $days = Plugin::getInstance()->getSettings()->holdDays;

        if ($days < 1) {
            return null;
        }

        return $this->now()->add(new DateInterval("P{$days}D"));
    }

    /**
     * Releases pending lots whose hold has run out.
     *
     * @return int How many lots were released.
     */
    public function promoteDueLots(?int $limit = null): int
    {
        $limit ??= Plugin::getInstance()->getSettings()->sweepBatchSize;

        $rows = (new Query())
            ->select(['id', 'userId', 'storeId'])
            ->from(Table::LOTS)
            ->where(['status' => Lot::STATUS_PENDING])
            ->andWhere(['not', ['dateAvailable' => null]])
            ->andWhere(['<=', 'dateAvailable', Db::prepareDateForDb($this->now())])
            ->limit($limit)
            ->all();

        return $this->_release($rows);
    }

    /**
     * Releases everything an order is holding — what a status change or a payment triggers.
     *
     * @return int
     */
    public function promoteOrderLots(Order $order): int
    {
        if (!$order->id) {
            return 0;
        }

        $rows = (new Query())
            ->select(['id', 'userId', 'storeId'])
            ->from(Table::LOTS)
            ->where(['status' => Lot::STATUS_PENDING, 'orderId' => $order->id])
            ->all();

        return $this->_release($rows);
    }

    /**
     * Expires lots that are past their date.
     *
     * The transaction written is for what was actually *left* in the lot, not its face value:
     * points already spent did not expire, they were used.
     *
     * @return int How many lots expired.
     */
    public function expireDueLots(?int $limit = null): int
    {
        if (!Plugin::getInstance()->getSettings()->expiryEnabled) {
            return 0;
        }

        $limit ??= Plugin::getInstance()->getSettings()->sweepBatchSize;

        $rows = (new Query())
            ->select(['id', 'userId', 'storeId', 'currency', 'remaining'])
            ->from(Table::LOTS)
            ->where(['status' => [Lot::STATUS_AVAILABLE, Lot::STATUS_PENDING]])
            ->andWhere(['not', ['dateExpires' => null]])
            ->andWhere(['<=', 'dateExpires', Db::prepareDateForDb($this->now())])
            ->orderBy(['dateExpires' => SORT_ASC])
            ->limit($limit)
            ->all();

        $count = 0;
        $ledger = Plugin::getInstance()->getLedger();

        foreach ($rows as $row) {
            $lot = $ledger->getLotById((int)$row['id']);

            if ($lot === null || $lot->status === Lot::STATUS_EXPIRED) {
                continue;
            }

            $remaining = $lot->remaining;

            Db::update(Table::LOTS, [
                'status' => Lot::STATUS_EXPIRED,
                'remaining' => 0,
            ], ['id' => $lot->id]);

            if ($remaining > 0) {
                $this->_writeExpiry($lot, $remaining);
            }

            Plugin::getInstance()->getAccounts()->clearMemo($lot->userId, $lot->storeId);
            Plugin::getInstance()->getAccounts()->refresh($lot->userId, $lot->storeId);
            $count++;
        }

        return $count;
    }

    /**
     * Expires whole balances that have sat untouched. Pro.
     *
     * "Untouched" means no movement in the ledger — earning *or* spending — which is the
     * definition every loyalty scheme's terms use and the only one a customer can act on.
     *
     * @return int How many accounts were emptied.
     */
    public function expireInactiveAccounts(?int $limit = null): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();

        if (!$settings->expiryEnabled || !$plugin->isPro() || $settings->inactivityExpiryDays === null) {
            return 0;
        }

        $limit ??= $settings->sweepBatchSize;
        $cutoff = $this->now()->sub(new DateInterval("P{$settings->inactivityExpiryDays}D"));

        $rows = (new Query())
            ->select(['userId', 'storeId'])
            ->from(Table::ACCOUNTS)
            ->where(['or', ['>', 'pointsBalance', 0], ['>', 'creditBalance', 0]])
            ->andWhere([
                'or',
                ['dateLastActivity' => null],
                ['<=', 'dateLastActivity', Db::prepareDateForDb($cutoff)],
            ])
            ->limit($limit)
            ->all();

        $count = 0;
        $ledger = $plugin->getLedger();

        foreach ($rows as $row) {
            $userId = (int)$row['userId'];
            $storeId = (int)$row['storeId'];
            $emptied = false;

            foreach ([Rule::CURRENCY_POINTS, Rule::CURRENCY_CREDIT] as $currency) {
                foreach ($ledger->getSpendableLots($userId, $storeId, $currency) as $lot) {
                    $remaining = $lot->remaining;

                    Db::update(Table::LOTS, [
                        'status' => Lot::STATUS_EXPIRED,
                        'remaining' => 0,
                    ], ['id' => $lot->id]);

                    $this->_writeExpiry($lot, $remaining, Craft::t('pointz', 'Expired after a period of inactivity'));
                    $emptied = true;
                }
            }

            if ($emptied) {
                $plugin->getAccounts()->clearMemo($userId, $storeId);
                $plugin->getAccounts()->refresh($userId, $storeId);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Takes back what a refunded order earned, and hands back what it spent.
     *
     * Reversal is measured cumulatively: a second partial refund takes the difference between
     * what should now be revoked and what already has been, so refunding 20% twice takes 40% in
     * total rather than 20% twice over or 40% the second time.
     *
     * @return array{revoked: float, returned: float, short: float}
     */
    public function reverseForRefund(Order $order, float $refundedTotal, ?int $authorId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $plugin = Plugin::getInstance();
        $result = ['revoked' => 0.0, 'returned' => 0.0, 'short' => 0.0];

        if (!$order->id) {
            return $result;
        }

        $orderValue = (float)$order->getTotalPrice();
        $ratio = $settings->onRefund === Settings::REVERSAL_FULL
            ? 1.0
            : ($orderValue > 0 ? min(1.0, $refundedTotal / $orderValue) : 1.0);

        if ($settings->onRefund !== Settings::REVERSAL_NONE) {
            foreach ([Rule::CURRENCY_POINTS, Rule::CURRENCY_CREDIT] as $currency) {
                $result['revoked'] += $this->_revokeShare($order, $currency, $ratio, $authorId, $result);
            }
        }

        if ($settings->returnRedeemedOnRefund) {
            foreach ($plugin->getRedemption()->returnRedeemed($order, $ratio, $authorId) as $transaction) {
                $result['returned'] += $transaction->amount;
            }
        }

        return $result;
    }

    /**
     * Lots that will expire inside the warning window, grouped by account — what an expiry notice
     * is built from.
     *
     * @return array<int, array{userId: int, storeId: int, currency: string, amount: float, dateExpires: string}>
     */
    public function getExpiringSoon(?int $days = null, int $limit = 500): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $days ??= $settings->expiryWarningDays;

        if (!$settings->expiryEnabled || $days === null) {
            return [];
        }

        $until = $this->now()->add(new DateInterval("P{$days}D"));

        return (new Query())
            ->select([
                'userId',
                'storeId',
                'currency',
                'amount' => 'SUM([[remaining]])',
                'dateExpires' => 'MIN([[dateExpires]])',
            ])
            ->from(Table::LOTS)
            ->where(['status' => Lot::STATUS_AVAILABLE])
            ->andWhere(['>', 'remaining', 0])
            ->andWhere(['not', ['dateExpires' => null]])
            ->andWhere(['between', 'dateExpires', Db::prepareDateForDb($this->now()), Db::prepareDateForDb($until)])
            ->groupBy(['userId', 'storeId', 'currency'])
            ->limit($limit)
            ->all();
    }

    public function now(): DateTime
    {
        return new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * @param array<int, array{id: int|string, userId: int|string, storeId: int|string}> $rows
     */
    private function _release(array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $accounts = Plugin::getInstance()->getAccounts();
        $ids = array_map(static fn(array $row) => (int)$row['id'], $rows);
        $touched = [];

        foreach ($rows as $row) {
            $touched[(int)$row['storeId'] . ':' . (int)$row['userId']] = [(int)$row['userId'], (int)$row['storeId']];
        }

        // The transaction ids are read *before* the lots move, so a concurrent sweep that has
        // already released some of them cannot leave their transactions marked pending.
        $transactionIds = (new Query())
            ->select(['transactionId'])
            ->from(Table::LOTS)
            ->where(['id' => $ids])
            ->column();

        $released = Db::update(Table::LOTS, ['status' => Lot::STATUS_AVAILABLE], [
            'id' => $ids,
            'status' => Lot::STATUS_PENDING,
        ]);

        if ($transactionIds) {
            Db::update(Table::TRANSACTIONS, ['status' => Transaction::STATUS_POSTED], [
                'id' => $transactionIds,
                'status' => Transaction::STATUS_PENDING,
            ]);
        }

        foreach ($touched as [$userId, $storeId]) {
            $accounts->clearMemo($userId, $storeId);
            $accounts->refresh($userId, $storeId);
        }

        return is_int($released) ? $released : count($rows);
    }

    /**
     * Revokes a currency's share of what an order earned, netting off anything already taken.
     */
    private function _revokeShare(Order $order, string $currency, float $ratio, ?int $authorId, array &$result): float
    {
        $ledger = Plugin::getInstance()->getLedger();
        $earned = 0.0;

        foreach ($ledger->getTransactionsForOrder($order->id, Transaction::KIND_EARN) as $transaction) {
            if ($transaction->currency === $currency) {
                $earned += $transaction->amount;
            }
        }

        if ($earned <= 0) {
            return 0.0;
        }

        $alreadyRevoked = 0.0;

        foreach ($ledger->getTransactionsForOrder($order->id, Transaction::KIND_REVERSE) as $transaction) {
            if ($transaction->currency === $currency) {
                $alreadyRevoked += abs($transaction->amount);
            }
        }

        $target = round($earned * $ratio, 5);
        $wanted = round($target - $alreadyRevoked, 5);

        if ($wanted <= 0) {
            return 0.0;
        }

        // Pending lots go first: value the customer cannot spend yet is the cheapest to take back.
        $lots = $this->_orderLots($order->id, $currency);

        $outcome = $ledger->revoke($lots, $wanted, [
            'orderId' => $order->id,
            'authorId' => $authorId,
            'note' => Craft::t('pointz', 'Reversed after a refund'),
        ]);

        $result['short'] += $outcome['short'];

        return $outcome['taken'];
    }

    /**
     * An order's own lots, pending before available, newest first — the order a revocation must
     * take them in.
     *
     * @return Lot[]
     */
    private function _orderLots(int $orderId, string $currency): array
    {
        $lots = Plugin::getInstance()->getLedger()->getLotsForOrder($orderId, $currency);

        usort($lots, static function(Lot $a, Lot $b) {
            $rank = static fn(Lot $lot) => $lot->status === Lot::STATUS_PENDING ? 0 : 1;

            return [$rank($a), -$a->id] <=> [$rank($b), -$b->id];
        });

        return array_values(array_filter($lots, static fn(Lot $lot) => $lot->remaining > 0
            && in_array($lot->status, [Lot::STATUS_PENDING, Lot::STATUS_AVAILABLE], true)));
    }

    private function _writeExpiry(Lot $lot, float $amount, ?string $note = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        Craft::$app->getDb()->createCommand()->insert(Table::TRANSACTIONS, [
            'storeId' => $lot->storeId,
            'userId' => $lot->userId,
            'currency' => $lot->currency,
            'kind' => Transaction::KIND_EXPIRE,
            'amount' => -$amount,
            'status' => Transaction::STATUS_POSTED,
            'orderId' => $lot->orderId,
            'ruleId' => $lot->ruleId,
            'note' => $note ?? Craft::t('pointz', 'Expired'),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
    }
}
