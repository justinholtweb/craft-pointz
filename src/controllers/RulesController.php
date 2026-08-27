<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\models\Store;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Managing earning rules.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('pointz-manageRules');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(?string $storeHandle = null): Response
    {
        $plugin = Plugin::getInstance();
        $store = $this->_store($storeHandle);
        $rules = $plugin->getRules()->getAllRules($store->id);

        return $this->renderTemplate('pointz/rules/_index', [
            'store' => $store,
            'stores' => Commerce::getInstance()->getStores()->getAllStores(),
            'rules' => $rules,
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
            // Lite runs one rule per store. A second would need conditions to choose between
            // them, and conditions are the Pro half of the bargain.
            'canAddRule' => $plugin->isPro() || $rules === [],
            'newRuleUrl' => UrlHelper::cpUrl("pointz/rules/{$store->handle}/new"),
        ]);
    }

    public function actionEdit(?string $storeHandle = null, ?int $ruleId = null, ?Rule $rule = null): Response
    {
        $plugin = Plugin::getInstance();
        $store = $this->_store($storeHandle);
        $rulesService = $plugin->getRules();

        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = $rulesService->getRuleById($ruleId);

                if ($rule === null) {
                    throw new NotFoundHttpException('Earning rule not found');
                }
            } else {
                if (!$plugin->isPro() && $rulesService->getAllRules($store->id) !== []) {
                    throw new ForbiddenHttpException('Pointz Lite supports one earning rule per store.');
                }

                $rule = new Rule(['storeId' => $store->id]);
            }
        }

        return $this->renderTemplate('pointz/rules/_edit', [
            'rule' => $rule,
            'store' => $store,
            'isNew' => !$rule->id,
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
            'eventOptions' => $this->_options(Rule::events()),
            'scopeOptions' => $this->_options(Rule::scopes()),
            'currencyOptions' => $this->_options(Rule::currencies()),
            'calculationOptions' => $this->_options(Rule::calculations()),
            'roundingOptions' => $this->_options(Rule::roundings()),
            'periodOptions' => $this->_options(Rule::periods()),
            'basisOptions' => $this->_basisOptions($rule->scope),
            'title' => $rule->id ? $rule->name : Craft::t('pointz', 'New earning rule'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $rulesService = $plugin->getRules();
        $id = $this->request->getBodyParam('id');
        $rule = $id ? $rulesService->getRuleById((int)$id) : new Rule();

        if ($rule === null) {
            throw new NotFoundHttpException('Earning rule not found');
        }

        $rule->storeId = (int)$this->request->getBodyParam('storeId', $rule->storeId);
        $rule->name = $this->request->getBodyParam('name', $rule->name);
        $rule->handle = $this->request->getBodyParam('handle', $rule->handle);
        $rule->enabled = (bool)$this->request->getBodyParam('enabled', $rule->enabled);
        $rule->event = $this->request->getBodyParam('event', $rule->event);
        $rule->calculation = $this->request->getBodyParam('calculation', $rule->calculation);
        $rule->basis = $this->request->getBodyParam('basis', $rule->basis);
        $rule->rate = (float)$this->request->getBodyParam('rate', $rule->rate);
        $rule->rounding = $this->request->getBodyParam('rounding', $rule->rounding);

        if ($plugin->isPro()) {
            $rule->scope = $this->request->getBodyParam('scope', $rule->scope);
            $rule->currency = $this->request->getBodyParam('currency', $rule->currency);
            $rule->multiplier = (float)$this->request->getBodyParam('multiplier', $rule->multiplier);
            $rule->minAward = $this->_number($this->request->getBodyParam('minAward'));
            $rule->maxAward = $this->_number($this->request->getBodyParam('maxAward'));
            $rule->maxPerUser = $this->_number($this->request->getBodyParam('maxPerUser'));
            $rule->maxPerUserPeriod = $this->request->getBodyParam('maxPerUserPeriod', $rule->maxPerUserPeriod);
            $rule->firstOrderOnly = (bool)$this->request->getBodyParam('firstOrderOnly');
            $rule->stopProcessing = (bool)$this->request->getBodyParam('stopProcessing');
            $rule->expireAfterDays = $this->_int($this->request->getBodyParam('expireAfterDays'));
            $rule->dateFrom = $this->_date($this->request->getBodyParam('dateFrom'));
            $rule->dateTo = $this->_date($this->request->getBodyParam('dateTo'));
            $rule->setOrderCondition($this->request->getBodyParam('orderCondition'));
            $rule->setUserCondition($this->request->getBodyParam('userCondition'));
            $rule->setPurchasableCondition($this->request->getBodyParam('purchasableCondition'));
        }

        if (!$rulesService->saveRule($rule)) {
            $this->setFailFlash(Craft::t('pointz', 'Couldn’t save the earning rule.'));

            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('pointz', 'Earning rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        return $this->asSuccess(
            Craft::t('pointz', 'Earning rule deleted.'),
            ['deleted' => Plugin::getInstance()->getRules()->deleteRuleById($id)]
        );
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decodeIfJson($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->getRules()->reorderRules($ids);

        return $this->asSuccess();
    }

    /**
     * The basis options for a scope, so the edit screen can swap them when the scope changes
     * rather than offering a combination that validation would reject.
     */
    public function actionBases(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $scope = (string)$this->request->getBodyParam('scope', Rule::SCOPE_ORDER);

        return $this->asJson(['options' => $this->_basisOptions($scope)]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function _basisOptions(string $scope): array
    {
        $labels = Rule::bases();
        $options = [];

        foreach (Rule::basesForScope($scope) as $value) {
            $options[] = ['value' => $value, 'label' => $labels[$value]];
        }

        return $options;
    }

    /**
     * @param array<string, string> $map
     * @return array<int, array{value: string, label: string}>
     */
    private function _options(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    private function _number(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float)$value;
    }

    private function _int(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int)$value;
    }

    private function _date(mixed $value): ?\DateTime
    {
        if (empty($value)) {
            return null;
        }

        $date = \craft\helpers\DateTimeHelper::toDateTime($value);

        return $date === false ? null : $date;
    }

    private function _store(?string $storeHandle): Store
    {
        $stores = Commerce::getInstance()->getStores();
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getCurrentStore();

        if ($store === null) {
            $store = $stores->getPrimaryStore();
        }

        if ($store === null) {
            throw new NotFoundHttpException('Store not found');
        }

        return $store;
    }
}
