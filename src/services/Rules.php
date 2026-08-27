<?php

namespace justinholtweb\pointz\services;

use Craft;
use craft\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;
use justinholtweb\pointz\records\RuleRecord;

/**
 * Earning rules: reading, ordering and saving them.
 *
 * Rules live in the database rather than project config, the same call Commerce makes for its own
 * discounts and shipping rules — they are commercial configuration a merchandiser changes on a
 * Friday afternoon, not schema that has to move between environments in lockstep.
 */
class Rules extends Component
{
    /** @var array<int, Rule[]>|null */
    private ?array $_rulesByStore = null;

    /**
     * @return Rule[]
     */
    public function getAllRules(?int $storeId = null): array
    {
        if ($this->_rulesByStore === null) {
            $this->_rulesByStore = [];

            $rows = $this->_query()
                ->orderBy(['storeId' => SORT_ASC, 'sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $this->_rulesByStore[(int)$row['storeId']][] = new Rule($row);
            }
        }

        if ($storeId === null) {
            return array_merge(...array_values($this->_rulesByStore ?: [[]]));
        }

        return $this->_rulesByStore[$storeId] ?? [];
    }

    /**
     * The rules that could fire for an event, in precedence order. `sortOrder` *is* precedence:
     * the first rule with "stop processing" set ends the run.
     *
     * Lite gets one rule per store. Rather than hiding the others, it uses the first enabled one —
     * so an install that downgrades keeps awarding something sensible instead of nothing.
     *
     * @return Rule[]
     */
    public function getActiveRules(int $storeId, string $event, ?DateTime $when = null): array
    {
        $rules = array_values(array_filter(
            $this->getAllRules($storeId),
            static fn(Rule $rule) => $rule->enabled && $rule->event === $event && $rule->isInWindow($when)
        ));

        if (!Plugin::getInstance()->isPro() && count($rules) > 1) {
            $rules = [$rules[0]];
        }

        return $rules;
    }

    public function getRuleById(int $id): ?Rule
    {
        foreach ($this->getAllRules() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function getRuleByHandle(string $handle, int $storeId): ?Rule
    {
        foreach ($this->getAllRules($storeId) as $rule) {
            if ($rule->handle === $handle) {
                return $rule;
            }
        }

        return null;
    }

    public function saveRule(Rule $rule, bool $runValidation = true): bool
    {
        $isNew = $rule->id === null;

        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $record = $isNew ? new RuleRecord() : RuleRecord::findOne($rule->id);

        if ($record === null) {
            throw new \InvalidArgumentException("No rule exists with the ID {$rule->id}.");
        }

        if ($isNew) {
            $record->sortOrder = ($this->_maxSortOrder($rule->storeId) ?? 0) + 1;
            $record->uid = StringHelper::UUID();
        }

        $record->storeId = $rule->storeId;
        $record->name = $rule->name;
        $record->handle = $rule->handle;
        $record->enabled = $rule->enabled;
        $record->event = $rule->event;
        $record->scope = $rule->scope;
        $record->currency = $rule->currency;
        $record->calculation = $rule->calculation;
        $record->basis = $rule->basis;
        $record->rate = $rule->rate;
        $record->multiplier = $rule->multiplier;
        $record->rounding = $rule->rounding;
        $record->minAward = $rule->minAward;
        $record->maxAward = $rule->maxAward;
        $record->maxPerUser = $rule->maxPerUser;
        $record->maxPerUserPeriod = $rule->maxPerUserPeriod;
        $record->firstOrderOnly = $rule->firstOrderOnly;
        $record->stopProcessing = $rule->stopProcessing;
        $record->expireAfterDays = $rule->expireAfterDays;
        $record->dateFrom = $rule->dateFrom ? Db::prepareDateForDb($rule->dateFrom) : null;
        $record->dateTo = $rule->dateTo ? Db::prepareDateForDb($rule->dateTo) : null;
        $record->orderCondition = $rule->getConditionJson('order');
        $record->userCondition = $rule->getConditionJson('user');
        $record->purchasableCondition = $rule->getConditionJson('purchasable');

        $record->save(false);

        $rule->id = $record->id;
        $rule->uid = $record->uid;
        $rule->sortOrder = $record->sortOrder;

        $this->_rulesByStore = null;

        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $record = RuleRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        // Transactions keep a `ruleId` with ON DELETE SET NULL, so the ledger survives the rule
        // that wrote it. A note nobody can trace back is still better than a hole in the history.
        $record->delete();
        $this->_rulesByStore = null;

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorderRules(array $ids): bool
    {
        foreach ($ids as $index => $id) {
            Db::update(Table::RULES, ['sortOrder' => $index + 1], ['id' => $id]);
        }

        $this->_rulesByStore = null;

        return true;
    }

    /**
     * How much a rule has already awarded one customer inside its cap period.
     */
    public function getAwardedInPeriod(Rule $rule, int $userId): float
    {
        if ($rule->maxPerUser === null) {
            return 0.0;
        }

        $query = (new Query())
            ->from(Table::TRANSACTIONS)
            ->where([
                'ruleId' => $rule->id,
                'userId' => $userId,
                'currency' => $rule->currency,
            ])
            ->andWhere(['>', 'amount', 0]);

        $since = $this->periodStart($rule->maxPerUserPeriod);

        if ($since !== null) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)]);
        }

        return (float)($query->sum('[[amount]]') ?? 0);
    }

    /**
     * The start of the current cap period, or null when the cap is for all time.
     */
    public function periodStart(string $period, ?DateTime $now = null): ?DateTime
    {
        $now ??= new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
        $start = (clone $now)->setTime(0, 0);

        return match ($period) {
            Rule::PERIOD_DAY => $start,
            Rule::PERIOD_WEEK => $start->modify('monday this week'),
            Rule::PERIOD_MONTH => $start->modify('first day of this month'),
            Rule::PERIOD_YEAR => $start->modify('first day of january this year'),
            default => null,
        };
    }

    /**
     * The store's handle, for building control-panel URLs.
     */
    public function getStoreHandle(?int $storeId): string
    {
        if (!$storeId || !Plugin::commerceIsReady()) {
            return 'primary';
        }

        $store = Commerce::getInstance()->getStores()->getStoreById($storeId);

        return $store?->handle ?? 'primary';
    }

    public function getStoreIdByHandle(?string $handle): ?int
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        $stores = Commerce::getInstance()->getStores();

        if (!$handle) {
            return $stores->getPrimaryStore()?->id;
        }

        return $stores->getStoreByHandle($handle)?->id ?? $stores->getPrimaryStore()?->id;
    }

    /**
     * Clears the memo. The checks call it after writing rules behind the service's back.
     */
    public function clearMemo(): void
    {
        $this->_rulesByStore = null;
    }

    private function _maxSortOrder(?int $storeId): ?int
    {
        $max = (new Query())
            ->from(Table::RULES)
            ->where(['storeId' => $storeId])
            ->max('[[sortOrder]]');

        return $max === null ? null : (int)$max;
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id',
                'storeId',
                'name',
                'handle',
                'enabled',
                'sortOrder',
                'event',
                'scope',
                'currency',
                'calculation',
                'basis',
                'rate',
                'multiplier',
                'rounding',
                'minAward',
                'maxAward',
                'maxPerUser',
                'maxPerUserPeriod',
                'firstOrderOnly',
                'stopProcessing',
                'expireAfterDays',
                'dateFrom',
                'dateTo',
                'orderCondition',
                'userCondition',
                'purchasableCondition',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::RULES);
    }
}
