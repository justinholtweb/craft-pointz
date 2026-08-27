<?php

namespace justinholtweb\pointz\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;
use yii\console\ExitCode;

/**
 * Handing out value from the command line — a goodwill run after an outage, or a launch bonus for
 * a group.
 */
class GrantController extends Controller
{
    /**
     * @var string What is being granted: `points` or `credit`.
     */
    public string $currency = Rule::CURRENCY_POINTS;

    /**
     * @var string|null A note recorded against every movement.
     */
    public ?string $note = null;

    /**
     * @var int|null The store. Defaults to the primary one.
     */
    public ?int $storeId = null;

    /**
     * @var bool Print what would happen without writing anything.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['currency', 'note', 'storeId', 'dryRun']);
    }

    /**
     * Grants one customer, by ID or email.
     */
    public function actionToUser(string $user, float $amount): int
    {
        $found = is_numeric($user)
            ? Craft::$app->getUsers()->getUserById((int)$user)
            : Craft::$app->getUsers()->getUserByUsernameOrEmail($user);

        if ($found === null) {
            $this->stderr("No such customer: $user\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $storeId = $this->_storeId();

        if ($this->dryRun) {
            $this->stdout("Would grant $amount $this->currency to {$found->email}.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $transaction = Plugin::getInstance()->getGrants()->grant(
            $found->id,
            $storeId,
            $this->currency,
            $amount,
            $this->note
        );

        $this->stdout("Granted. Balance is now $transaction->balanceAfter.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Grants everybody in a user group, under one batch ID so the whole run can be reversed.
     */
    public function actionToGroup(string $group, float $amount): int
    {
        $userGroup = Craft::$app->getUserGroups()->getGroupByHandle($group);

        if ($userGroup === null) {
            $this->stderr("No such user group: $group\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $userIds = User::find()->groupId($userGroup->id)->status(null)->ids();

        if (!$userIds) {
            $this->stdout("That group has no members.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout('Would grant ' . $amount . ' ' . $this->currency . ' to ' . count($userIds) . " customer(s).\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->getGrants()->grantMany(
            $userIds,
            $this->_storeId(),
            $this->currency,
            $amount,
            $this->note
        );

        $this->stdout("Granted to {$result['granted']} customer(s).\n", Console::FG_GREEN);
        $this->stdout("Batch: {$result['batchId']}\n");

        if ($result['failed']) {
            $this->stdout('Failed: ' . count($result['failed']) . "\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Takes back whatever is left of a batch.
     */
    public function actionRevert(string $batchId): int
    {
        $result = Plugin::getInstance()->getGrants()->reverseBatch($batchId);

        $this->stdout("Reversed {$result['reversed']} movement(s).\n", Console::FG_GREEN);

        if ($result['short']) {
            $this->stdout("{$result['short']} had already been spent and could not be taken back in full.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    private function _storeId(): int
    {
        return $this->storeId ?? \craft\commerce\Plugin::getInstance()->getStores()->getPrimaryStore()->id;
    }
}
