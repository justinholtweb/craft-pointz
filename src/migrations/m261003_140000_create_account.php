<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use justinholtweb\pointz\db\Table;

/**
 * Custom-event rules can pay an email address nobody has an account for, by creating an inactive
 * one — a newsletter subscriber's welcome coupon, waiting for them when they register.
 */
class m261003_140000_create_account extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::RULES, 'createAccount')) {
            $this->addColumn(Table::RULES, 'createAccount', $this->boolean()->notNull()->defaultValue(false)->after('reviewPurchasedOnly'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::RULES, 'createAccount')) {
            $this->dropColumn(Table::RULES, 'createAccount');
        }

        return true;
    }
}
