<?php

namespace justinholtweb\pointz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\Plugin;
use yii\console\ExitCode;

/**
 * Awarding orders that completed before Pointz was watching. Pro.
 */
class BackfillController extends Controller
{
    /**
     * @var string|null Only orders placed on or after this date.
     */
    public ?string $from = null;

    /**
     * @var string|null Only orders placed on or before this date.
     */
    public ?string $to = null;

    /**
     * @var int|null Only orders in this store.
     */
    public ?int $storeId = null;

    /**
     * @var int|null Stop after this many orders.
     */
    public ?int $limit = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['from', 'to', 'storeId', 'limit']);
    }

    /**
     * Prints what a backfill would award, writing nothing.
     */
    public function actionPlan(): int
    {
        $plan = Plugin::getInstance()->getBackfill()->plan($this->_criteria());

        foreach (array_slice($plan['rows'], 0, 25) as $row) {
            $this->stdout(sprintf("  %-16s %s points\n", $row['reference'], $row['points']));
        }

        if (count($plan['rows']) > 25) {
            $this->stdout('  … and ' . (count($plan['rows']) - 25) . " more\n");
        }

        $this->stdout("\nOrders to award: {$plan['orders']}\n", Console::FG_GREEN);
        $this->stdout("Skipped (already earned, or no rule matches): {$plan['skipped']}\n");
        $this->stdout("Points: {$plan['points']}\n");
        $this->stdout("Credit: {$plan['credit']}\n");

        return ExitCode::OK;
    }

    /**
     * Awards them.
     */
    public function actionRun(): int
    {
        $plan = Plugin::getInstance()->getBackfill()->plan($this->_criteria());

        $this->stdout("This will award {$plan['orders']} order(s): {$plan['points']} points, {$plan['credit']} credit.\n");

        if ($this->interactive && !$this->confirm('Go ahead?')) {
            return ExitCode::OK;
        }

        try {
            $result = Plugin::getInstance()->getBackfill()->run($this->_criteria());
        } catch (PointzException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Awarded {$result['orders']} order(s).\n", Console::FG_GREEN);
        $this->stdout("Batch: {$result['batchId']}\n");
        $this->stdout("Revert with: craft pointz/backfill/revert {$result['batchId']}\n");

        if ($result['failed']) {
            $this->stdout("Failed: {$result['failed']} — see the logs.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Takes a backfill back.
     */
    public function actionRevert(string $batchId): int
    {
        $result = Plugin::getInstance()->getBackfill()->revert($batchId);

        $this->stdout("Reversed {$result['reversed']} movement(s).\n", Console::FG_GREEN);

        if ($result['short']) {
            $this->stdout("{$result['short']} had already been spent and could not be taken back in full.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * @return array{from: \DateTime|null, to: \DateTime|null, storeId: int|null, limit: int|null}
     */
    private function _criteria(): array
    {
        return [
            'from' => $this->from ? DateTimeHelper::toDateTime($this->from) ?: null : null,
            'to' => $this->to ? DateTimeHelper::toDateTime($this->to) ?: null : null,
            'storeId' => $this->storeId,
            'limit' => $this->limit,
        ];
    }
}
