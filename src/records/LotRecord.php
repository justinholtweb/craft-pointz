<?php

namespace justinholtweb\pointz\records;

use craft\db\ActiveRecord;
use justinholtweb\pointz\db\Table;

/**
 * @property int $id
 */
class LotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOTS;
    }
}
