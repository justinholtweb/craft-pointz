<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\events\TransactionEvent;
use justinholtweb\pointz\models\Lot;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;
use yii\db\Expression;

/**
 * Every movement of value goes through here. There is no other writer.
 *
 * Positive movements create a **lot** — an amount with its own remaining figure and expiry date.
 * Negative movements consume lots soonest-expiry-first and record exactly which lots they took
 * from, in `pointz_lot_uses`. That record is what lets a refund put value back where it came from
 * instead of minting new value with a new expiry date, and it is what makes the balance a
 * derivable number rather than a running total nobody can audit.
 */
class Ledger extends Component
{
    /**
     * @event TransactionEvent Raised after a movement is written and the balance refreshed.
     */
    public const EVENT_AFTER_TRANSACTION = 'afterTransaction';

    /**
     * Adds value.
     *
     * @param array{kind?: string, orderId?: int|null, ruleId?: int|null, authorId?: int|null,
     *              note?: string|null, reference?: string|null, batchId?: string|null,
     *              pending?: bool, dateAvailable?: DateTime|null, dateExpires?: DateTime|null} $config
     */
    public function credit(int $userId, int $storeId, string $currency, float $amount, array $config = []): Transaction
    {
        if ($amount <= 0) {
            throw new PointzException('A credit must be a positive amount.');
        }

        return $this->_locked($userId, $storeId, function() use ($userId, $storeId, $currency, $amount, $config) {
            $pending = (bool)($config['pending'] ?? false);

            $transaction = $this->_writeTransaction([
                'storeId' => $storeId,
                'userId' => $userId,
                'currency' => $currency,
                'kind' => $config['kind'] ?? Transaction::KIND_EARN,
                'amount' => $amount,
                'status' => $pending ? Transaction::STATUS_PENDING : Transaction::STATUS_POSTED,
                'orderId' => $config['orderId'] ?? null,
                'ruleId' => $config['ruleId'] ?? null,
                'authorId' => $config['authorId'] ?? null,
                'reversesId' => $config['reversesId'] ?? null,
                'batchId' => $config['batchId'] ?? null,
                'reference' => $config['reference'] ?? null,
                'note' => $config['note'] ?? null,
            ]);

            $this->_writeLot([
                'transactionId' => $transaction->id,
                'storeId' => $storeId,
                'userId' => $userId,
                'currency' => $currency,
                'amount' => $amount,
                'remaining' => $amount,
                'status' => $pending ? Lot::STATUS_PENDING : Lot::STATUS_AVAILABLE,
                'orderId' => $config['orderId'] ?? null,
                'ruleId' => $config['ruleId'] ?? null,
                'dateAvailable' => $config['dateAvailable'] ?? null,
                'dateExpires' => $config['dateExpires'] ?? null,
            ]);

            return $this->_settle($transaction);
        });
    }

    /**
     * Spends value, consuming lots soonest-expiry-first.
     *
     * @param array{kind?: string, orderId?: int|null, authorId?: int|null, note?: string|null,
     *              reference?: string|null, batchId?: string|null, allowPartial?: bool} $config
     * @throws PointzException if the balance is short and `allowPartial` is not set.
     */
    public function debit(int $userId, int $storeId, string $currency, float $amount, array $config = []): Transaction
    {
        if ($amount <= 0) {
            throw new PointzException('A debit must be a positive amount.');
        }

        return $this->_locked($userId, $storeId, function() use ($userId, $storeId, $currency, $amount, $config) {
            $lots = $this->getSpendableLots($userId, $storeId, $currency);
            $available = array_sum(array_map(static fn(Lot $lot) => $lot->remaining, $lots));
            $wanted = $amount;

            if ($available + 0.00001 < $wanted) {
                if (!($config['allowPartial'] ?? false)) {
                    throw new PointzException(sprintf(
                        'Not enough %s: %s available, %s wanted.',
                        $currency,
                        $this->_num($available),
                        $this->_num($wanted)
                    ));
                }

                $wanted = $available;
            }

            if ($wanted <= 0) {
                throw new PointzException('There is nothing to spend.');
            }

            $transaction = $this->_writeTransaction([
                'storeId' => $storeId,
                'userId' => $userId,
                'currency' => $currency,
                'kind' => $config['kind'] ?? Transaction::KIND_REDEEM,
                'amount' => -$wanted,
                'status' => Transaction::STATUS_POSTED,
                'orderId' => $config['orderId'] ?? null,
                'ruleId' => $config['ruleId'] ?? null,
                'authorId' => $config['authorId'] ?? null,
                'batchId' => $config['batchId'] ?? null,
                'reference' => $config['reference'] ?? null,
                'note' => $config['note'] ?? null,
            ]);

            $this->_consume($lots, $wanted, $transaction->id);

            return $this->_settle($transaction);
        });
    }

    /**
     * Puts value back into the lots a spend took it from.
     *
     * A lot that has since expired or been revoked cannot take its value back — that would make a
     * dead lot spendable again — so its share is re-issued as a fresh lot instead. Handing a
     * customer back something that is already expired is not a refund.
     *
     * @return Transaction|null Null when there was nothing left to restore.
     */
    public function restore(Transaction $spend, ?float $amount = null, array $config = []): ?Transaction
    {
        if ($spend->amount >= 0) {
            throw new PointzException('Only a spend can be restored.');
        }

        $uses = (new Query())
            ->from(Table::LOT_USES)
            ->where(['transactionId' => $spend->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $restorable = 0.0;

        foreach ($uses as $use) {
            $restorable += (float)$use['amount'] - (float)$use['restored'];
        }

        $wanted = $amount === null ? $restorable : min($amount, $restorable);

        if ($wanted <= 0) {
            return null;
        }

        return $this->_locked($spend->userId, $spend->storeId, function() use ($spend, $uses, $wanted, $config) {
            $transaction = $this->_writeTransaction([
                'storeId' => $spend->storeId,
                'userId' => $spend->userId,
                'currency' => $spend->currency,
                'kind' => $config['kind'] ?? Transaction::KIND_REFUND,
                'amount' => $wanted,
                'status' => Transaction::STATUS_POSTED,
                'orderId' => $config['orderId'] ?? $spend->orderId,
                'authorId' => $config['authorId'] ?? null,
                'reversesId' => $spend->id,
                'batchId' => $config['batchId'] ?? null,
                'note' => $config['note'] ?? null,
            ]);

            $left = $wanted;

            foreach ($uses as $use) {
                if ($left <= 0) {
                    break;
                }

                $share = min($left, (float)$use['amount'] - (float)$use['restored']);

                if ($share <= 0) {
                    continue;
                }

                $lot = $this->getLotById((int)$use['lotId']);

                if ($lot !== null && $lot->status === Lot::STATUS_AVAILABLE) {
                    Db::update(Table::LOTS, [
                        'remaining' => new Expression('[[remaining]] + :share', [':share' => $share]),
                    ], ['id' => $lot->id]);
                } else {
                    // The original lot is gone. Re-issue the share with a fresh lifetime rather
                    // than pouring it into an expired container.
                    $this->_writeLot([
                        'transactionId' => $transaction->id,
                        'storeId' => $spend->storeId,
                        'userId' => $spend->userId,
                        'currency' => $spend->currency,
                        'amount' => $share,
                        'remaining' => $share,
                        'status' => Lot::STATUS_AVAILABLE,
                        'orderId' => $config['orderId'] ?? $spend->orderId,
                        'dateExpires' => Plugin::getInstance()->getLifecycle()->expiryDateFor(null),
                    ]);
                }

                Db::update(Table::LOT_USES, [
                    'restored' => new Expression('[[restored]] + :share', [':share' => $share]),
                ], ['id' => $use['id']]);

                $left -= $share;
            }

            Db::update(Table::TRANSACTIONS, ['status' => Transaction::STATUS_REVERSED], ['id' => $spend->id]);

            return $this->_settle($transaction);
        });
    }

    /**
     * Takes value back out of specific lots — a refund undoing what an order earned. Whatever is
     * left in each lot goes first; a lot the customer has already spent can only give back what
     * remains, and the shortfall is reported rather than pushing the balance negative.
     *
     * @param Lot[] $lots
     * @return array{taken: float, short: float, transaction: Transaction|null}
     */
    public function revoke(array $lots, float $amount, array $config = []): array
    {
        if (!$lots) {
            return ['taken' => 0.0, 'short' => $amount, 'transaction' => null];
        }

        $first = reset($lots);
        $userId = $first->userId;
        $storeId = $first->storeId;
        $currency = $first->currency;

        return $this->_locked($userId, $storeId, function() use ($lots, $amount, $config, $userId, $storeId, $currency) {
            $left = $amount;
            $taken = 0.0;
            $touched = [];

            foreach ($lots as $lot) {
                if ($left <= 0) {
                    break;
                }

                $share = min($left, $lot->remaining);

                if ($share <= 0) {
                    continue;
                }

                $remaining = round($lot->remaining - $share, 5);

                Db::update(Table::LOTS, [
                    'remaining' => $remaining,
                    // A lot emptied by a revocation is revoked, not spent: it must not come back
                    // to life if the same order is refunded twice.
                    'status' => $remaining <= 0 ? Lot::STATUS_REVOKED : $lot->status,
                ], ['id' => $lot->id]);

                $touched[] = $lot->id;
                $taken += $share;
                $left -= $share;
            }

            if ($taken <= 0) {
                return ['taken' => 0.0, 'short' => $amount, 'transaction' => null];
            }

            $transaction = $this->_writeTransaction([
                'storeId' => $storeId,
                'userId' => $userId,
                'currency' => $currency,
                'kind' => $config['kind'] ?? Transaction::KIND_REVERSE,
                'amount' => -$taken,
                'status' => Transaction::STATUS_POSTED,
                'orderId' => $config['orderId'] ?? null,
                'authorId' => $config['authorId'] ?? null,
                'batchId' => $config['batchId'] ?? null,
                'note' => $config['note'] ?? null,
            ]);

            return [
                'taken' => $taken,
                'short' => round(max(0, $amount - $taken), 5),
                'transaction' => $this->_settle($transaction),
            ];
        });
    }

    /**
     * The lots a spend may draw on, in the order it must draw on them: whatever expires soonest,
     * then whatever was earned first. Any other order throws value away.
     *
     * @return Lot[]
     */
    public function getSpendableLots(int $userId, int $storeId, string $currency = Rule::CURRENCY_POINTS): array
    {
        $rows = $this->_lotQuery()
            ->where([
                'userId' => $userId,
                'storeId' => $storeId,
                'currency' => $currency,
                'status' => Lot::STATUS_AVAILABLE,
            ])
            ->andWhere(['>', 'remaining', 0])
            // Portable "nulls last": a lot that never expires is spent only once the dated ones
            // are gone.
            ->orderBy([
                new Expression('CASE WHEN [[dateExpires]] IS NULL THEN 1 ELSE 0 END'),
                'dateExpires' => SORT_ASC,
                'id' => SORT_ASC,
            ])
            ->all();

        return array_map(static fn(array $row) => new Lot($row), $rows);
    }

    public function getLotById(int $id): ?Lot
    {
        $row = $this->_lotQuery()->where(['id' => $id])->one();

        return $row ? new Lot($row) : null;
    }

    /**
     * The lots an order's earnings created.
     *
     * @return Lot[]
     */
    public function getLotsForOrder(int $orderId, ?string $currency = null): array
    {
        $query = $this->_lotQuery()->where(['orderId' => $orderId]);

        if ($currency !== null) {
            $query->andWhere(['currency' => $currency]);
        }

        $rows = $query->orderBy(['id' => SORT_ASC])->all();

        return array_map(static fn(array $row) => new Lot($row), $rows);
    }

    public function getTransactionById(int $id): ?Transaction
    {
        $row = $this->_transactionQuery()->where(['id' => $id])->one();

        return $row ? new Transaction($row) : null;
    }

    /**
     * @return Transaction[]
     */
    public function getTransactionsForOrder(int $orderId, ?string $kind = null): array
    {
        $query = $this->_transactionQuery()->where(['orderId' => $orderId]);

        if ($kind !== null) {
            $query->andWhere(['kind' => $kind]);
        }

        $rows = $query->orderBy(['id' => SORT_ASC])->all();

        return array_map(static fn(array $row) => new Transaction($row), $rows);
    }

    /**
     * @return Transaction[]
     */
    public function getTransactions(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->getTransactionsQuery($criteria)
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return array_map(static fn(array $row) => new Transaction($row), $query->all());
    }

    /**
     * @param array{userId?: int, storeId?: int, currency?: string, kind?: string|string[],
     *              orderId?: int, batchId?: string, status?: string} $criteria
     */
    public function getTransactionsQuery(array $criteria = []): Query
    {
        $query = $this->_transactionQuery();

        foreach (['userId', 'storeId', 'currency', 'kind', 'orderId', 'batchId', 'status', 'ruleId'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return $query;
    }

    /**
     * How much of a spend has not yet been handed back.
     */
    public function getUnrestoredAmount(Transaction $spend): float
    {
        $row = (new Query())
            ->select([
                'used' => 'SUM([[amount]])',
                'back' => 'SUM([[restored]])',
            ])
            ->from(Table::LOT_USES)
            ->where(['transactionId' => $spend->id])
            ->one();

        return round((float)($row['used'] ?? 0) - (float)($row['back'] ?? 0), 5);
    }

    /**
     * Runs the callback holding the account's lock and inside a database transaction, so a
     * half-written movement is never visible and two checkouts cannot spend the same lot.
     */
    private function _locked(int $userId, int $storeId, callable $callback): mixed
    {
        $settings = Plugin::getInstance()->getSettings();
        $mutex = Craft::$app->getMutex();
        $lock = "pointz:account:$storeId:$userId";

        if (!$mutex->acquire($lock, $settings->lockTimeout)) {
            throw new PointzException("Could not take the balance lock for user $userId.");
        }

        $db = Craft::$app->getDb();
        $dbTransaction = $db->beginTransaction();

        try {
            $result = $callback();
            $dbTransaction->commit();
        } catch (\Throwable $e) {
            $dbTransaction->rollBack();
            throw $e;
        } finally {
            $mutex->release($lock);
        }

        return $result;
    }

    private function _writeTransaction(array $values): Transaction
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        $row = array_merge($values, [
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        Craft::$app->getDb()->createCommand()->insert(Table::TRANSACTIONS, $row)->execute();

        $row['id'] = (int)Craft::$app->getDb()->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::TRANSACTIONS));

        return new Transaction($row);
    }

    private function _writeLot(array $values): int
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        foreach (['dateAvailable', 'dateExpires'] as $key) {
            if (($values[$key] ?? null) instanceof DateTime) {
                $values[$key] = Db::prepareDateForDb($values[$key]);
            }
        }

        Craft::$app->getDb()->createCommand()->insert(Table::LOTS, array_merge($values, [
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]))->execute();

        return (int)Craft::$app->getDb()->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::LOTS));
    }

    /**
     * @param Lot[] $lots
     */
    private function _consume(array $lots, float $amount, int $transactionId): void
    {
        $left = $amount;
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        foreach ($lots as $lot) {
            if ($left <= 0.00001) {
                break;
            }

            $share = min($left, $lot->remaining);

            if ($share <= 0) {
                continue;
            }

            Db::update(Table::LOTS, [
                'remaining' => round($lot->remaining - $share, 5),
            ], ['id' => $lot->id]);

            Craft::$app->getDb()->createCommand()->insert(Table::LOT_USES, [
                'lotId' => $lot->id,
                'transactionId' => $transactionId,
                'amount' => $share,
                'restored' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $left -= $share;
        }
    }

    /**
     * Refreshes the balance cache, stamps the transaction with it, logs and announces.
     */
    private function _settle(Transaction $transaction): Transaction
    {
        $accounts = Plugin::getInstance()->getAccounts();
        $accounts->clearMemo($transaction->userId, $transaction->storeId);
        $account = $accounts->refresh($transaction->userId, $transaction->storeId);

        $balance = $account->balanceFor($transaction->currency);

        Db::update(Table::TRANSACTIONS, ['balanceAfter' => $balance], ['id' => $transaction->id]);
        $transaction->balanceAfter = $balance;

        if (Plugin::getInstance()->getSettings()->logTransactions) {
            Craft::info(sprintf(
                '%s %s %s for user %d in store %d; balance now %s',
                $transaction->kind,
                $this->_num($transaction->amount),
                $transaction->currency,
                $transaction->userId,
                $transaction->storeId,
                $this->_num($balance)
            ), 'pointz');
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_TRANSACTION)) {
            $this->trigger(self::EVENT_AFTER_TRANSACTION, new TransactionEvent([
                'transaction' => $transaction,
                'account' => $account,
            ]));
        }

        return $transaction;
    }

    private function _num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.') ?: '0';
    }

    private function _transactionQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'storeId',
                'userId',
                'currency',
                'kind',
                'amount',
                'balanceAfter',
                'status',
                'orderId',
                'ruleId',
                'authorId',
                'reversesId',
                'batchId',
                'reference',
                'note',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::TRANSACTIONS);
    }

    private function _lotQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'transactionId',
                'storeId',
                'userId',
                'currency',
                'amount',
                'remaining',
                'status',
                'orderId',
                'ruleId',
                'dateAvailable',
                'dateExpires',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::LOTS);
    }
}
