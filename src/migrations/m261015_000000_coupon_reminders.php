<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use justinholtweb\pointz\db\Table;

/**
 * 5.3: reminders counted from the day a coupon was issued, threshold rules that fire once per
 * order or spend the whole balance, review rules that pay once per product, and a note of which
 * imported codes Pointz limited to a single use, so a revert can lift the limit again.
 *
 * A coupon now carries its whole reminder schedule, and `dateRemind` is the next one due rather
 * than the only one. A reminder already sent under the old scheme has nothing left to send, so its
 * `dateRemind` is cleared — otherwise the first sweep after the update would send it again.
 */
class m261015_000000_coupon_reminders extends Migration
{
    public function safeUp(): bool
    {
        $rules = [
            'thresholdOncePerOrder' => $this->boolean()->notNull()->defaultValue(false),
            'thresholdReset' => $this->boolean()->notNull()->defaultValue(false),
            'reviewOncePerProduct' => $this->boolean()->notNull()->defaultValue(false),
            'couponRemindAfterDays' => $this->string(),
        ];

        foreach ($rules as $name => $type) {
            if (!$this->db->columnExists(Table::RULES, $name)) {
                $this->addColumn(Table::RULES, $name, $type);
            }
        }

        $coupons = [
            'reminders' => $this->text(),
            'madeSingleUse' => $this->boolean()->notNull()->defaultValue(false),
        ];

        foreach ($coupons as $name => $type) {
            if (!$this->db->columnExists(Table::COUPONS, $name)) {
                $this->addColumn(Table::COUPONS, $name, $type);
            }
        }

        $this->update(Table::COUPONS, ['dateRemind' => null], ['not', ['dateReminded' => null]], [], false);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261015_000000_coupon_reminders cannot be reverted.\n";

        return false;
    }
}
