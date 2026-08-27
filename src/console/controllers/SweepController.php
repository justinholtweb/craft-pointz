<?php

namespace justinholtweb\pointz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pointz\Plugin;
use yii\console\ExitCode;

/**
 * The scheduled half of Pointz: holds clearing, value expiring, idle balances closing.
 *
 * Nothing expires because a date passed — it expires because this ran. Schedule
 * `pointz/sweep/run` daily and the plugin keeps itself honest; do not, and balances simply never
 * expire, which is a defensible way for it to fail.
 */
class SweepController extends Controller
{
    /**
     * @var int|null How many lots or accounts to handle. Defaults to the sweep batch size.
     */
    public ?int $limit = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * Runs every sweep in the order they have to happen: release first, expire second.
     */
    public function actionRun(): int
    {
        $promoted = Plugin::getInstance()->getLifecycle()->promoteDueLots($this->limit);
        $expired = Plugin::getInstance()->getLifecycle()->expireDueLots($this->limit);
        $idle = Plugin::getInstance()->getLifecycle()->expireInactiveAccounts($this->limit);

        $this->stdout("Released: $promoted\n", Console::FG_GREEN);
        $this->stdout("Expired: $expired\n", Console::FG_GREEN);
        $this->stdout("Idle balances closed: $idle\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Releases held value whose hold has run out.
     */
    public function actionPromote(): int
    {
        $count = Plugin::getInstance()->getLifecycle()->promoteDueLots($this->limit);
        $this->stdout("Released $count lot(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Expires value that is past its date.
     */
    public function actionExpire(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->expiryEnabled) {
            $this->stdout("Expiry is switched off in the settings; nothing to do.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->getLifecycle()->expireDueLots($this->limit);
        $this->stdout("Expired $count lot(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Closes balances that have sat untouched for the configured window. Pro.
     */
    public function actionInactive(): int
    {
        $count = Plugin::getInstance()->getLifecycle()->expireInactiveAccounts($this->limit);
        $this->stdout("Closed $count idle balance(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists value about to expire — what an expiry warning is built from.
     */
    public function actionExpiring(?int $days = null): int
    {
        $rows = Plugin::getInstance()->getLifecycle()->getExpiringSoon($days);

        if (!$rows) {
            $this->stdout("Nothing is expiring in that window.\n");

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "user %d, store %d: %s %s expiring %s\n",
                $row['userId'],
                $row['storeId'],
                rtrim(rtrim(number_format((float)$row['amount'], 5, '.', ''), '0'), '.'),
                $row['currency'],
                $row['dateExpires']
            ));
        }

        $this->stdout(count($rows) . " account(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
