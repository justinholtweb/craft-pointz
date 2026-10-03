<?php

namespace justinholtweb\pointz\migrations;

use craft\db\Migration;
use justinholtweb\pointz\db\Table;

/**
 * 5.1: rewards that are not an order — reviews, birthdays, balance thresholds and custom events —
 * and coupons as something a rule can hand out.
 *
 * Rules gain the columns their new triggers need; `pointz_coupons` records every code Pointz has
 * issued, who owns it and when it stops working. The Commerce discount behind a coupon rule is
 * created by the rule, not here.
 */
class m261003_120000_rewards extends Migration
{
    public function safeUp(): bool
    {
        $columns = [
            'eventHandle' => $this->string(),
            'thresholdPoints' => $this->decimal(19, 5),
            'thresholdSpend' => $this->boolean()->notNull()->defaultValue(true),
            'birthdayField' => $this->string(),
            'reviewRequiresText' => $this->boolean()->notNull()->defaultValue(true),
            'reviewPurchasedOnly' => $this->boolean()->notNull()->defaultValue(false),
            'couponType' => $this->string(8)->notNull()->defaultValue('percent'),
            'couponAmount' => $this->decimal(19, 5),
            'couponValidDays' => $this->integer(),
            'couponRemindDays' => $this->integer(),
            'couponNotify' => $this->boolean()->notNull()->defaultValue(false),
            'couponDiscountId' => $this->integer(),
        ];

        foreach ($columns as $name => $type) {
            if (!$this->db->columnExists(Table::RULES, $name)) {
                $this->addColumn(Table::RULES, $name, $type);
            }
        }

        if (!$this->db->tableExists(Table::COUPONS)) {
            Install::createCouponsTable($this);
        }

        $this->addForeignKey(null, Table::RULES, ['couponDiscountId'], Install::COMMERCE_DISCOUNTS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261003_120000_rewards cannot be reverted.\n";

        return false;
    }
}
