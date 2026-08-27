<?php

namespace justinholtweb\pointz\elements\conditions\orders;

use craft\commerce\base\HasStoreInterface;
use craft\commerce\base\StoreTrait;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use yii\base\NotSupportedException;

/**
 * The condition builder deciding which orders an earning rule fires on.
 *
 * It runs against an order that has just completed, so the completion-only rules — order status,
 * paid, total paid, date ordered — are all meaningful here in a way they are not at cart time.
 */
class PointzOrderCondition extends OrderCondition implements HasStoreInterface
{
    use StoreTrait;

    /**
     * @inheritdoc
     */
    public ?string $elementType = Order::class;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['storeId'], 'safe'];

        return $rules;
    }

    protected function config(): array
    {
        return array_merge(parent::config(), $this->toArray(['storeId']));
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        throw new NotSupportedException('Earning rules are matched against a single order, not queried.');
    }
}
