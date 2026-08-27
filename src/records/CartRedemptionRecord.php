<?php

namespace justinholtweb\pointz\records;

use craft\db\ActiveRecord;
use justinholtweb\pointz\db\Table;

/**
 * @property int $id
 */
class CartRedemptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CART_REDEMPTIONS;
    }
}
