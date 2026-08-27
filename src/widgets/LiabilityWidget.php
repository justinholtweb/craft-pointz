<?php

namespace justinholtweb\pointz\widgets;

use Craft;
use craft\base\Widget;
use craft\commerce\Plugin as Commerce;
use justinholtweb\pointz\Plugin;

/**
 * What the loyalty scheme owes.
 *
 * Unredeemed points are a liability, and the number finance asks for is the one nobody can find
 * in Commerce. This is that number, in money as well as in points.
 */
class LiabilityWidget extends Widget
{
    /**
     * @var int|null Which store. Null follows the primary one.
     */
    public ?int $storeId = null;

    public static function displayName(): string
    {
        return Craft::t('pointz', 'Pointz liability');
    }

    public static function icon(): ?string
    {
        // A path, not an alias: Craft does not register one for a plugin's own namespace, and
        // `Craft::getAlias()` throws rather than returning null on an unknown one.
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }

    public function getTitle(): ?string
    {
        return Craft::t('pointz', 'Pointz liability');
    }

    public function getBodyHtml(): ?string
    {
        if (!Plugin::commerceIsReady()) {
            return Craft::t('pointz', 'Craft Commerce is not installed.');
        }

        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores();
        $store = $this->storeId ? $stores->getStoreById($this->storeId) : $stores->getPrimaryStore();

        if ($store === null) {
            return Craft::t('pointz', 'No store found.');
        }

        $totals = $plugin->getAccounts()->getStoreTotals($store->id);

        return Craft::$app->getView()->renderTemplate('pointz/_widget', [
            'store' => $store,
            'totals' => $totals,
            'value' => $plugin->getRedemption()->valueForPoints($totals['points']),
            'pendingValue' => $plugin->getRedemption()->valueForPoints($totals['pendingPoints']),
            'settings' => $plugin->getSettings(),
            'plugin' => $plugin,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        $stores = Commerce::getInstance()->getStores()->getAllStores();

        if (count($stores) < 2) {
            return null;
        }

        $options = [];

        foreach ($stores as $store) {
            $options[] = ['value' => $store->id, 'label' => $store->name];
        }

        return Craft::$app->getView()->renderTemplateMacro('_includes/forms.twig', 'selectField', [[
            'label' => Craft::t('pointz', 'Store'),
            'id' => 'storeId',
            'name' => 'storeId',
            'options' => $options,
            'value' => $this->storeId,
        ]]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['storeId'], 'integer'],
            [['storeId'], 'safe'],
        ]);
    }
}
