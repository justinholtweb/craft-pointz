<?php

namespace justinholtweb\pointz\events;

use justinholtweb\pointz\models\Account;
use justinholtweb\pointz\models\Transaction;
use yii\base\Event;

/**
 * Raised after a movement has been written and the balance refreshed. Read-only by design: the
 * ledger is append-only, so there is nothing here to change after the fact.
 */
class TransactionEvent extends Event
{
    public Transaction $transaction;
    public Account $account;
}
