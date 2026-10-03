<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\StringHelper;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\models\Rule;

/**
 * Pointz install migration.
 */
class Install extends Migration
{
    private const COMMERCE_STORES = '{{%commerce_stores}}';
    private const COMMERCE_ORDERS = '{{%commerce_orders}}';
    public const COMMERCE_DISCOUNTS = '{{%commerce_discounts}}';
    public const COMMERCE_COUPONS = '{{%commerce_coupons}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        self::createCouponsTable($this);
        $this->seedDefaultRules();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Children first: uses point at lots, lots at transactions, transactions at rules.
        $this->dropTableIfExists(Table::COUPONS);
        $this->dropTableIfExists(Table::LOT_USES);
        $this->dropTableIfExists(Table::LOTS);
        $this->dropTableIfExists(Table::TRANSACTIONS);
        $this->dropTableIfExists(Table::CART_REDEMPTIONS);
        $this->dropTableIfExists(Table::RULES);
        $this->dropTableIfExists(Table::ACCOUNTS);

        return true;
    }

    private function createTables(): void
    {
        // A cache of the lot sums, not a source of truth. `pointz/accounts/recalculate` rebuilds
        // every column here from the ledger, and the checks assert that it agrees.
        $this->createTable(Table::ACCOUNTS, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'pointsBalance' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'pendingPoints' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'creditBalance' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'pendingCredit' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'lifetimePoints' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'lifetimeCredit' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'dateLastActivity' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The ledger: append-only, one row per movement, signed. `balanceAfter` is a convenience
        // for the CP and for support questions — it is never read back as an input.
        $this->createTable(Table::TRANSACTIONS, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'currency' => $this->string(8)->notNull()->defaultValue('points'),
            'kind' => $this->string(16)->notNull(),
            'amount' => $this->decimal(19, 5)->notNull(),
            'balanceAfter' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'status' => $this->string(16)->notNull()->defaultValue('posted'),
            'orderId' => $this->integer(),
            'ruleId' => $this->integer(),
            'authorId' => $this->integer(),
            'reversesId' => $this->integer(),
            'batchId' => $this->string(36),
            'reference' => $this->string(255),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Every positive movement becomes a lot. Redemption consumes lots, expiry retires them,
        // and a reversal puts value back into the exact lot it came out of — which is only
        // possible because the remaining figure lives here rather than being inferred.
        $this->createTable(Table::LOTS, [
            'id' => $this->primaryKey(),
            'transactionId' => $this->integer()->notNull(),
            'storeId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'currency' => $this->string(8)->notNull()->defaultValue('points'),
            'amount' => $this->decimal(19, 5)->notNull(),
            'remaining' => $this->decimal(19, 5)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('available'),
            'orderId' => $this->integer(),
            'ruleId' => $this->integer(),
            'dateAvailable' => $this->dateTime(),
            'dateExpires' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // What a spend took, and from where. `restored` is how a partial refund knows it has
        // already put half of it back.
        $this->createTable(Table::LOT_USES, [
            'id' => $this->primaryKey(),
            'lotId' => $this->integer()->notNull(),
            'transactionId' => $this->integer()->notNull(),
            'amount' => $this->decimal(19, 5)->notNull(),
            'restored' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RULES, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            // Rules install switched off. Adding a loyalty plugin must never start handing out
            // points to a live store before anyone has looked at the numbers.
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer(),
            'event' => $this->string(16)->notNull()->defaultValue('order'),
            'scope' => $this->string(16)->notNull()->defaultValue('order'),
            'currency' => $this->string(8)->notNull()->defaultValue('points'),
            'calculation' => $this->string(16)->notNull()->defaultValue('ratio'),
            'basis' => $this->string(32)->notNull()->defaultValue('itemSubtotal'),
            'rate' => $this->decimal(19, 5)->notNull()->defaultValue(1),
            'multiplier' => $this->decimal(19, 5)->notNull()->defaultValue(1),
            'rounding' => $this->string(8)->notNull()->defaultValue('down'),
            'minAward' => $this->decimal(19, 5),
            'maxAward' => $this->decimal(19, 5),
            'maxPerUser' => $this->decimal(19, 5),
            'maxPerUserPeriod' => $this->string(16)->notNull()->defaultValue('ever'),
            'firstOrderOnly' => $this->boolean()->notNull()->defaultValue(false),
            'stopProcessing' => $this->boolean()->notNull()->defaultValue(false),
            'expireAfterDays' => $this->integer(),
            'dateFrom' => $this->dateTime(),
            'dateTo' => $this->dateTime(),
            'orderCondition' => $this->text(),
            'userCondition' => $this->text(),
            'purchasableCondition' => $this->text(),
            // A custom event's handle — `formie:newsletter`, `dispatch:club`, or the site's own.
            'eventHandle' => $this->string(),
            // A threshold rule fires when the points balance reaches this, and by default spends it.
            'thresholdPoints' => $this->decimal(19, 5),
            'thresholdSpend' => $this->boolean()->notNull()->defaultValue(true),
            // The handle of the user field a birthday rule reads.
            'birthdayField' => $this->string(),
            'reviewRequiresText' => $this->boolean()->notNull()->defaultValue(true),
            'reviewPurchasedOnly' => $this->boolean()->notNull()->defaultValue(false),
            // What a coupon rule hands out. The Commerce discount is created by the rule and
            // replaced, never edited, when the amount changes — so a code already issued keeps
            // the value it was issued with.
            'couponType' => $this->string(8)->notNull()->defaultValue('percent'),
            'couponAmount' => $this->decimal(19, 5),
            'couponValidDays' => $this->integer(),
            'couponRemindDays' => $this->integer(),
            'couponNotify' => $this->boolean()->notNull()->defaultValue(false),
            'couponDiscountId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The customer's *intent* for a cart. Nothing here is spent — the adjuster reads it and
        // clamps it on every recalculation, and completion is what turns it into a debit.
        $this->createTable(Table::CART_REDEMPTIONS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            // Who asked. Only their own balance may be spent on the cart — see Redemption::spenderId().
            'userId' => $this->integer()->null(),
            'points' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'credit' => $this->decimal(19, 5)->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // One account per customer per store, enforced by the database: two concurrent earns for
        // a new customer would otherwise each create one and split the balance in half.
        $this->createIndex(null, Table::ACCOUNTS, ['storeId', 'userId'], true);

        $this->createIndex(null, Table::TRANSACTIONS, ['storeId', 'userId', 'currency'], false);
        $this->createIndex(null, Table::TRANSACTIONS, ['orderId'], false);
        $this->createIndex(null, Table::TRANSACTIONS, ['kind'], false);
        $this->createIndex(null, Table::TRANSACTIONS, ['status'], false);
        $this->createIndex(null, Table::TRANSACTIONS, ['batchId'], false);
        $this->createIndex(null, Table::TRANSACTIONS, ['ruleId'], false);

        // The FIFO read: available lots for one account, oldest expiry first.
        $this->createIndex(null, Table::LOTS, ['storeId', 'userId', 'currency', 'status'], false);
        $this->createIndex(null, Table::LOTS, ['status', 'dateExpires'], false);
        $this->createIndex(null, Table::LOTS, ['status', 'dateAvailable'], false);
        $this->createIndex(null, Table::LOTS, ['orderId'], false);
        $this->createIndex(null, Table::LOTS, ['transactionId'], false);

        $this->createIndex(null, Table::LOT_USES, ['transactionId'], false);
        $this->createIndex(null, Table::LOT_USES, ['lotId'], false);

        $this->createIndex(null, Table::RULES, ['storeId', 'handle'], true);
        $this->createIndex(null, Table::RULES, ['storeId', 'sortOrder'], false);
        $this->createIndex(null, Table::RULES, ['event', 'enabled'], false);

        $this->createIndex(null, Table::CART_REDEMPTIONS, ['orderId'], true);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::ACCOUNTS, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ACCOUNTS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::TRANSACTIONS, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TRANSACTIONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TRANSACTIONS, ['orderId'], self::COMMERCE_ORDERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TRANSACTIONS, ['ruleId'], Table::RULES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TRANSACTIONS, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TRANSACTIONS, ['reversesId'], Table::TRANSACTIONS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::LOTS, ['transactionId'], Table::TRANSACTIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOTS, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOTS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOTS, ['orderId'], self::COMMERCE_ORDERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::LOTS, ['ruleId'], Table::RULES, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::LOT_USES, ['lotId'], Table::LOTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOT_USES, ['transactionId'], Table::TRANSACTIONS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::RULES, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RULES, ['couponDiscountId'], self::COMMERCE_DISCOUNTS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::CART_REDEMPTIONS, ['orderId'], self::COMMERCE_ORDERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CART_REDEMPTIONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
    }

    /**
     * Every coupon Pointz has issued. Shared by the install and the 5.1 migration.
     *
     * The code is copied here rather than read through `couponId`, because Commerce's coupon row
     * is deleted when a code expires and the customer's history should still say what they had.
     * `type` and `amount` are what the code was worth when it was issued, for the same reason.
     */
    public static function createCouponsTable(Migration $migration): void
    {
        $migration->createTable(Table::COUPONS, [
            'id' => $migration->primaryKey(),
            'storeId' => $migration->integer()->notNull(),
            'userId' => $migration->integer()->notNull(),
            'ruleId' => $migration->integer(),
            'discountId' => $migration->integer(),
            'couponId' => $migration->integer(),
            'transactionId' => $migration->integer(),
            'orderId' => $migration->integer(),
            'code' => $migration->string()->notNull(),
            'type' => $migration->string(8)->notNull()->defaultValue('percent'),
            'amount' => $migration->decimal(19, 5)->notNull()->defaultValue(0),
            'status' => $migration->string(16)->notNull()->defaultValue('active'),
            'reference' => $migration->string(),
            'dateExpires' => $migration->dateTime(),
            'dateRemind' => $migration->dateTime(),
            'dateReminded' => $migration->dateTime(),
            'dateUsed' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::COUPONS, ['code'], true);
        $migration->createIndex(null, Table::COUPONS, ['storeId', 'userId', 'status'], false);
        $migration->createIndex(null, Table::COUPONS, ['status', 'dateExpires'], false);
        $migration->createIndex(null, Table::COUPONS, ['status', 'dateRemind'], false);
        $migration->createIndex(null, Table::COUPONS, ['ruleId', 'userId'], false);
        $migration->createIndex(null, Table::COUPONS, ['discountId'], false);

        $migration->addForeignKey(null, Table::COUPONS, ['storeId'], self::COMMERCE_STORES, ['id'], 'CASCADE', null);
        $migration->addForeignKey(null, Table::COUPONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $migration->addForeignKey(null, Table::COUPONS, ['ruleId'], Table::RULES, ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, Table::COUPONS, ['discountId'], self::COMMERCE_DISCOUNTS, ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, Table::COUPONS, ['couponId'], self::COMMERCE_COUPONS, ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, Table::COUPONS, ['transactionId'], Table::TRANSACTIONS, ['id'], 'SET NULL', null);
        $migration->addForeignKey(null, Table::COUPONS, ['orderId'], self::COMMERCE_ORDERS, ['id'], 'SET NULL', null);
    }

    /**
     * Seeds one disabled "1 point per unit spent" rule per store, so the rules screen opens on
     * something to read rather than on an empty state — and so nothing is awarded until somebody
     * turns it on.
     */
    private function seedDefaultRules(): void
    {
        $storeIds = (new \craft\db\Query())
            ->select(['id'])
            ->from(self::COMMERCE_STORES)
            ->column($this->db);

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');

        foreach ($storeIds as $storeId) {
            $this->insert(Table::RULES, [
                'storeId' => $storeId,
                'name' => 'Points on every order',
                'handle' => 'pointsOnEveryOrder',
                'enabled' => false,
                'sortOrder' => 1,
                'event' => Rule::EVENT_ORDER,
                'scope' => Rule::SCOPE_ORDER,
                'currency' => Rule::CURRENCY_POINTS,
                'calculation' => Rule::CALC_RATIO,
                'basis' => Rule::BASIS_ITEM_SUBTOTAL,
                'rate' => 1,
                'multiplier' => 1,
                'rounding' => Rule::ROUND_DOWN,
                'maxPerUserPeriod' => Rule::PERIOD_EVER,
                'firstOrderOnly' => false,
                'stopProcessing' => false,
                'dateCreated' => $timestamp,
                'dateUpdated' => $timestamp,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }
}
