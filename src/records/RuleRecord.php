<?php

namespace justinholtweb\pointz\records;

use craft\db\ActiveRecord;
use justinholtweb\pointz\db\Table;

/**
 * @property int $id
 * @property int $storeId
 * @property string $name
 * @property string $handle
 * @property bool $enabled
 * @property int $sortOrder
 * @property string $event
 * @property string $scope
 * @property string $currency
 * @property string $calculation
 * @property string $basis
 * @property float $rate
 * @property float $multiplier
 * @property string $rounding
 * @property float|null $minAward
 * @property float|null $maxAward
 * @property float|null $maxPerUser
 * @property string|null $maxPerUserPeriod
 * @property bool $firstOrderOnly
 * @property bool $stopProcessing
 * @property int|null $expireAfterDays
 * @property string|null $dateFrom
 * @property string|null $dateTo
 * @property string|null $orderCondition
 * @property string|null $userCondition
 * @property string|null $purchasableCondition
 * @property string|null $eventHandle
 * @property float|null $thresholdPoints
 * @property bool $thresholdSpend
 * @property string|null $birthdayField
 * @property bool $reviewRequiresText
 * @property bool $reviewPurchasedOnly
 * @property bool $createAccount
 * @property string $couponType
 * @property float|null $couponAmount
 * @property int|null $couponValidDays
 * @property int|null $couponRemindDays
 * @property bool $couponNotify
 * @property int|null $couponDiscountId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RULES;
    }
}
