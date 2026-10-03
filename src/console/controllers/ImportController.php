<?php

namespace justinholtweb\pointz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\Plugin;
use yii\console\ExitCode;

/**
 * Bringing a loyalty programme over from another system: opening balances and outstanding coupons.
 *
 * Both take a CSV file with a header row, run as one batch, and can be undone with
 * `pointz/import/revert <batch>`. Run with `--dry-run` first: it checks every row and writes nothing.
 */
class ImportController extends Controller
{
    /**
     * @var int|null The store balances go into. Defaults to the primary one.
     */
    public ?int $storeId = null;

    /**
     * @var string The currency for rows that do not say: `points` or `credit`.
     */
    public string $currency = 'points';

    /**
     * @var string|null A note recorded against rows that do not have their own.
     */
    public ?string $note = null;

    /**
     * @var int|null Remind a coupon's owner this many days before it expires, for rows with no reminder date.
     */
    public ?int $remindDays = null;

    /**
     * @var bool Check every row and print what would happen, without writing anything.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'balances' => array_merge($options, ['storeId', 'currency', 'note', 'dryRun']),
            'coupons' => array_merge($options, ['remindDays', 'dryRun']),
            default => $options,
        };
    }

    /**
     * Opening balances: one lot per row. Columns: user, amount, and optionally currency, expires,
     * note, reference.
     */
    public function actionBalances(string $file): int
    {
        try {
            $import = Plugin::getInstance()->getImport();
            $result = $import->importBalances($import->readCsv($file), [
                'storeId' => $this->storeId,
                'currency' => $this->currency,
                'note' => $this->note,
                // The file's contents, so running the same file twice skips every row.
                'source' => substr(sha1_file($file) ?: '', 0, 16) ?: null,
                'dryRun' => $this->dryRun,
            ]);
        } catch (PointzException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $verb = $this->dryRun ? 'Would import' : 'Imported';
        $totals = implode(', ', array_map(
            static fn(string $currency, float $total) => "$total $currency",
            array_keys($result['totals']),
            $result['totals'],
        ));

        $this->stdout("$verb {$result['imported']} balance(s)" . ($totals ? " ($totals)" : '') . ".\n", Console::FG_GREEN);
        $this->_problems($result['skipped'], $result['failed']);

        if (!$this->dryRun && $result['imported']) {
            $this->stdout("Batch: {$result['batchId']}\n");
        }

        return $result['failed'] ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Outstanding coupons that already exist in Commerce. Columns: code, user, and optionally
     * expires, issued, remind.
     */
    public function actionCoupons(string $file): int
    {
        try {
            $import = Plugin::getInstance()->getImport();
            $result = $import->importCoupons($import->readCsv($file), [
                'remindDays' => $this->remindDays,
                'dryRun' => $this->dryRun,
            ]);
        } catch (PointzException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $verb = $this->dryRun ? 'Would take over' : 'Took over';
        $this->stdout("$verb {$result['imported']} coupon(s) on " . count($result['discounts']) . " discount(s).\n", Console::FG_GREEN);

        if ($result['madeSingleUse']) {
            $this->stdout(($this->dryRun ? 'Would limit ' : 'Limited ') . "{$result['madeSingleUse']} code(s) with no use limit to a single use.\n");
        }

        if ($result['discounts']) {
            $this->stdout("Pointz will delete each of these discounts once its last code is used or expires:\n");

            foreach ($result['discounts'] as $id => $name) {
                $this->stdout("  #$id $name\n");
            }
        }

        $this->_problems($result['skipped'], $result['failed']);

        if (!$this->dryRun && $result['imported']) {
            $this->stdout("Batch: {$result['batchId']}\n");
        }

        return $result['failed'] ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Undoes an import: takes back what is left of its balances and hands its unused coupons back
     * to Commerce.
     */
    public function actionRevert(string $batchId): int
    {
        $result = Plugin::getInstance()->getImport()->revert($batchId);

        $this->stdout("Reversed {$result['reversed']} balance(s); handed {$result['released']} coupon(s) back to Commerce.\n", Console::FG_GREEN);

        if ($result['short']) {
            $this->stdout("{$result['short']} balance(s) had already been spent and could not be taken back in full.\n", Console::FG_YELLOW);
        }

        if ($result['kept']) {
            $this->stdout("{$result['kept']} coupon(s) have been used or have expired since, and stay in the history.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * @param array<int, string> $skipped
     * @param array<int, string> $failed
     */
    private function _problems(array $skipped, array $failed): void
    {
        if ($skipped) {
            $this->stdout('Skipped ' . count($skipped) . ":\n", Console::FG_YELLOW);

            foreach ($skipped as $line => $reason) {
                $this->stdout("  line $line: $reason\n");
            }
        }

        if ($failed) {
            $this->stdout('Failed ' . count($failed) . ":\n", Console::FG_RED);

            foreach ($failed as $line => $reason) {
                $this->stdout("  line $line: $reason\n");
            }
        }
    }
}
