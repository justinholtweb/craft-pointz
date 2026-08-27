<?php

namespace justinholtweb\pointz\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;

/**
 * A customer's balances in one store.
 *
 * Every figure here is a cache of the lots. Nothing writes to it directly except
 * `services\Accounts::refresh()`, and `pointz/accounts/recalculate` can rebuild the whole table
 * from the ledger — which is the point of keeping the lots authoritative.
 */
class Account extends Model
{
    public ?int $id = null;
    public ?int $storeId = null;
    public ?int $userId = null;
    public float $pointsBalance = 0;
    public float $pendingPoints = 0;
    public float $creditBalance = 0;
    public float $pendingCredit = 0;
    public float $lifetimePoints = 0;
    public float $lifetimeCredit = 0;
    public ?DateTime $dateLastActivity = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?User $_user = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateLastActivity']);
    }

    public function getUser(): ?User
    {
        if ($this->_user === null && $this->userId) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
        }

        return $this->_user;
    }

    public function setUser(?User $user): void
    {
        $this->_user = $user;
        $this->userId = $user?->id;
    }

    /**
     * The spendable balance in one currency. Pending value is deliberately excluded: a customer
     * who can see it but cannot spend it will write in about it exactly once.
     */
    public function balanceFor(string $currency): float
    {
        return $currency === Rule::CURRENCY_CREDIT ? $this->creditBalance : $this->pointsBalance;
    }

    public function pendingFor(string $currency): float
    {
        return $currency === Rule::CURRENCY_CREDIT ? $this->pendingCredit : $this->pendingPoints;
    }
}
