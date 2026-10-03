<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\pointz\db\Table;

/**
 * Records who asked for each cart redemption.
 *
 * A guest who types a registered customer's email at checkout becomes that customer as far as
 * Commerce is concerned, and before 5.0.1 that was enough to spend their points and store credit.
 * A redemption now only spends from the account of the signed-in customer who asked for it.
 *
 * Intents already in carts were made without that record, so they are cleared: a customer with
 * points applied to a cart right now applies them again.
 */
class m261003_000000_redemption_owner extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::CART_REDEMPTIONS, 'userId')) {
            $this->addColumn(Table::CART_REDEMPTIONS, 'userId', $this->integer()->null()->after('orderId'));
            $this->addForeignKey(null, Table::CART_REDEMPTIONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        }

        $this->delete(Table::CART_REDEMPTIONS, ['userId' => null]);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261003_000000_redemption_owner cannot be reverted.\n";

        return false;
    }
}
