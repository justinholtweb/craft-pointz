<?php

namespace justinholtweb\pointz\elements\conditions\purchasables;

use craft\commerce\elements\conditions\purchasables\CatalogPricingRulePurchasableCondition;
use craft\elements\db\ElementQueryInterface;
use yii\base\NotSupportedException;

/**
 * Which purchasables a line-item rule applies to — SKU, purchasable, type, or product category,
 * the same four Commerce offers its own catalog pricing rules.
 */
class PointzPurchasableCondition extends CatalogPricingRulePurchasableCondition
{
    public function modifyQuery(ElementQueryInterface $query): void
    {
        throw new NotSupportedException('Earning rules are matched against a single purchasable, not queried.');
    }
}
