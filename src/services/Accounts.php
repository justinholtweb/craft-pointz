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
use justinholtweb\pointz\models\Account;
use justinholtweb\pointz\models\Lot;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;

/**
 * Balances.
 *
 * Every column on an account is a **cache** of the lots beneath it. `refresh()` recomputes one
 * account from those lots and is called after every write; `recalculateAll()` does the whole
 * table. Nothing else may write to the accounts table — if a number here is ever wrong, it is
 * because something skipped `Ledger`, and recalculating fixes it.
 */
class Accounts extends Component
{
    /**
     * @var array<string, Account> Memoized per request. A cart recalculation asks for the same
     *                             balance several times over.
     */
    private array $_accounts = [];

    public function getAccount(int $userId, int $storeId): ?Account
    {
        $key = "$storeId:$userId";

        if (array_key_exists($key, $this->_accounts)) {
            return $this->_accounts[$key];
        }

        $row = $this->_query()
            ->where(['userId' => $userId, 'storeId' => $storeId])
            ->one();

        return $this->_accounts[$key] = $row ? new Account($row) : null;
    }

    /**
     * The account, creating an empty one if the customer has never had a balance in this store.
     *
     * The unique index on (storeId, userId) is what makes this safe under concurrency: two
     * requests racing to create the same account both attempt the insert, one loses, and the
     * loser reads the winner's row rather than creating a second account that splits the balance.
     */
    public function getOrCreateAccount(int $userId, int $storeId): Account
    {
        $account = $this->getAccount($userId, $storeId);

        if ($account !== null) {
            return $account;
        }

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        Craft::$app->getDb()->createCommand()
            ->upsert(Table::ACCOUNTS, [
                'storeId' => $storeId,
                'userId' => $userId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], false)
            ->execute();

        unset($this->_accounts["$storeId:$userId"]);

        $account = $this->getAccount($userId, $storeId);

        if ($account === null) {
            throw new \RuntimeException("Could not create a Pointz account for user $userId in store $storeId.");
        }

        return $account;
    }

    /**
     * Recomputes one account from its lots and rewrites the cached row.
     */
    public function refresh(int $userId, int $storeId): Account
    {
        $sums = $this->_lotSums($userId, $storeId);
        $lastActivity = (new Query())
            ->from(Table::TRANSACTIONS)
            ->where(['userId' => $userId, 'storeId' => $storeId])
            ->max('[[dateCreated]]');

        $values = [
            'pointsBalance' => $sums[Rule::CURRENCY_POINTS][Lot::STATUS_AVAILABLE] ?? 0,
            'pendingPoints' => $sums[Rule::CURRENCY_POINTS][Lot::STATUS_PENDING] ?? 0,
            'creditBalance' => $sums[Rule::CURRENCY_CREDIT][Lot::STATUS_AVAILABLE] ?? 0,
            'pendingCredit' => $sums[Rule::CURRENCY_CREDIT][Lot::STATUS_PENDING] ?? 0,
            'lifetimePoints' => $this->_lifetime($userId, $storeId, Rule::CURRENCY_POINTS),
            'lifetimeCredit' => $this->_lifetime($userId, $storeId, Rule::CURRENCY_CREDIT),
            'dateLastActivity' => $lastActivity,
        ];

        $this->getOrCreateAccount($userId, $storeId);

        Db::update(Table::ACCOUNTS, $values, ['userId' => $userId, 'storeId' => $storeId]);

        unset($this->_accounts["$storeId:$userId"]);

        return $this->getAccount($userId, $storeId);
    }

    /**
     * Rebuilds every account from the ledger. The recovery tool, and the assertion the checks make
     * after every scenario: if the cache and the lots disagree, the cache is wrong.
     *
     * @return int How many accounts were rewritten.
     */
    public function recalculateAll(?callable $progress = null): int
    {
        $pairs = (new Query())
            ->select(['userId', 'storeId'])
            ->from(Table::ACCOUNTS)
            ->all();

        // A customer with lots but somehow no account row still has to be rebuilt.
        $orphans = (new Query())
            ->select(['userId', 'storeId'])
            ->distinct()
            ->from(Table::LOTS)
            ->all();

        $seen = [];
        $count = 0;

        foreach (array_merge($pairs, $orphans) as $pair) {
            $key = $pair['storeId'] . ':' . $pair['userId'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $this->refresh((int)$pair['userId'], (int)$pair['storeId']);
            $count++;

            if ($progress !== null) {
                $progress($count);
            }
        }

        return $count;
    }

    /**
     * The spendable balance, straight from the cache.
     */
    public function getBalance(int $userId, int $storeId, string $currency = Rule::CURRENCY_POINTS): float
    {
        $account = $this->getAccount($userId, $storeId);

        return $account?->balanceFor($currency) ?? 0.0;
    }

    /**
     * The spendable balance recomputed from the lots — what the cache *should* say.
     */
    public function getLotBalance(int $userId, int $storeId, string $currency = Rule::CURRENCY_POINTS): float
    {
        $sums = $this->_lotSums($userId, $storeId);

        return (float)($sums[$currency][Lot::STATUS_AVAILABLE] ?? 0);
    }

    /**
     * Accounts for the control panel index.
     */
    public function getAccountsQuery(?int $storeId = null): Query
    {
        $query = $this->_query();

        if ($storeId !== null) {
            $query->andWhere(['storeId' => $storeId]);
        }

        return $query;
    }

    /**
     * Total unredeemed value across a store — the liability the finance team asks about.
     *
     * @return array{points: float, pendingPoints: float, credit: float, pendingCredit: float, customers: int}
     */
    public function getStoreTotals(int $storeId): array
    {
        $row = (new Query())
            ->select([
                'points' => 'SUM([[pointsBalance]])',
                'pendingPoints' => 'SUM([[pendingPoints]])',
                'credit' => 'SUM([[creditBalance]])',
                'pendingCredit' => 'SUM([[pendingCredit]])',
                'customers' => 'COUNT(*)',
            ])
            ->from(Table::ACCOUNTS)
            ->where(['storeId' => $storeId])
            ->one();

        return [
            'points' => (float)($row['points'] ?? 0),
            'pendingPoints' => (float)($row['pendingPoints'] ?? 0),
            'credit' => (float)($row['credit'] ?? 0),
            'pendingCredit' => (float)($row['pendingCredit'] ?? 0),
            'customers' => (int)($row['customers'] ?? 0),
        ];
    }

    /**
     * Clears the per-request memo. The ledger calls this after a write, and the checks call it
     * after reaching around the service.
     */
    public function clearMemo(?int $userId = null, ?int $storeId = null): void
    {
        if ($userId === null || $storeId === null) {
            $this->_accounts = [];
            return;
        }

        unset($this->_accounts["$storeId:$userId"]);
    }

    /**
     * Remaining value per currency per status, in one query.
     *
     * @return array<string, array<string, float>>
     */
    private function _lotSums(int $userId, int $storeId): array
    {
        $rows = (new Query())
            ->select(['currency', 'status', 'total' => 'SUM([[remaining]])'])
            ->from(Table::LOTS)
            ->where(['userId' => $userId, 'storeId' => $storeId])
            ->andWhere(['status' => [Lot::STATUS_AVAILABLE, Lot::STATUS_PENDING]])
            ->groupBy(['currency', 'status'])
            ->all();

        $sums = [];

        foreach ($rows as $row) {
            $sums[$row['currency']][$row['status']] = (float)$row['total'];
        }

        return $sums;
    }

    /**
     * Everything ever credited that was not taken back — the lot's full face value, whether or not
     * it has since been spent or expired. Revoked lots are excluded because they were never
     * really earned.
     */
    private function _lifetime(int $userId, int $storeId, string $currency): float
    {
        return (float)(new Query())
            ->from(Table::LOTS)
            ->where(['userId' => $userId, 'storeId' => $storeId, 'currency' => $currency])
            ->andWhere(['not', ['status' => Lot::STATUS_REVOKED]])
            ->sum('[[amount]]') ?? 0;
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id',
                'storeId',
                'userId',
                'pointsBalance',
                'pendingPoints',
                'creditBalance',
                'pendingCredit',
                'lifetimePoints',
                'lifetimeCredit',
                'dateLastActivity',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::ACCOUNTS);
    }
}
