<?php

namespace justinholtweb\pointz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pointz\Plugin;
use yii\console\ExitCode;

/**
 * Balance maintenance.
 */
class AccountsController extends Controller
{
    /**
     * Rebuilds every cached balance from the lots.
     *
     * The recovery tool, and the thing to run after restoring a database or importing a ledger
     * from somewhere else. Harmless: it computes what the lots already say.
     */
    public function actionRecalculate(): int
    {
        $count = Plugin::getInstance()->getAccounts()->recalculateAll(function(int $done) {
            if ($done % 100 === 0) {
                $this->stdout("  {$done}…\n");
            }
        });

        $this->stdout("Rebuilt $count account(s) from the ledger.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Prints one customer's balance and where it sits.
     */
    public function actionShow(int $userId, ?int $storeId = null): int
    {
        $plugin = Plugin::getInstance();
        $storeId ??= \craft\commerce\Plugin::getInstance()->getStores()->getPrimaryStore()->id;
        $account = $plugin->getAccounts()->getAccount($userId, $storeId);

        if ($account === null) {
            $this->stdout("That customer has no balance in store $storeId.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("Points: $account->pointsBalance (pending $account->pendingPoints)\n");
        $this->stdout("Credit: $account->creditBalance\n");
        $this->stdout("Lifetime: $account->lifetimePoints\n\n");

        foreach ($plugin->getLedger()->getSpendableLots($userId, $storeId) as $lot) {
            $this->stdout(sprintf(
                "  lot %d: %s of %s, expires %s\n",
                $lot->id,
                $lot->remaining,
                $lot->amount,
                $lot->dateExpires?->format('Y-m-d') ?? 'never'
            ));
        }

        return ExitCode::OK;
    }
}
