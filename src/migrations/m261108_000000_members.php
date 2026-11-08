<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use justinholtweb\pointz\db\Table;

/**
 * 5.5: loyalty programme membership. The member group lives in the settings; this table keeps when
 * each customer joined and left.
 */
class m261108_000000_members extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::MEMBERS)) {
            Install::createMembersTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::MEMBERS);

        return true;
    }
}
