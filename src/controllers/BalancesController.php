<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Account;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Customer balances, and the screen where a person moves one by hand.
 */
class BalancesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('pointz-viewBalances');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();
        $search = trim((string)$this->request->getParam('search', ''));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 50;

        $query = (new Query())
            ->select([
                'a.id',
                'a.userId',
                'a.storeId',
                'a.pointsBalance',
                'a.pendingPoints',
                'a.creditBalance',
                'a.lifetimePoints',
                'a.dateLastActivity',
                'username' => 'u.username',
                'email' => 'u.email',
                'firstName' => 'u.firstName',
                'lastName' => 'u.lastName',
            ])
            ->from(['a' => Table::ACCOUNTS])
            ->innerJoin(['u' => CraftTable::USERS], '[[u.id]] = [[a.userId]]')
            ->where(['a.storeId' => $store?->id])
            ->orderBy(['a.pointsBalance' => SORT_DESC, 'a.id' => SORT_ASC]);

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'u.email', $search],
                ['like', 'u.username', $search],
                ['like', 'u.firstName', $search],
                ['like', 'u.lastName', $search],
            ]);
        }

        $total = (int)$query->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return $this->renderTemplate('pointz/balances/_index', [
            'rows' => $rows,
            'store' => $store,
            'stores' => $stores->getAllStores(),
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totals' => $store ? $plugin->getAccounts()->getStoreTotals($store->id) : null,
            'settings' => $plugin->getSettings(),
            'plugin' => $plugin,
        ]);
    }

    public function actionDetail(int $userId): Response
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null) {
            throw new NotFoundHttpException('Customer not found');
        }

        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();

        if ($store === null) {
            throw new NotFoundHttpException('Store not found');
        }

        $account = $plugin->getAccounts()->getAccount($userId, $store->id)
            ?? new Account(['userId' => $userId, 'storeId' => $store->id]);

        return $this->renderTemplate('pointz/balances/_detail', [
            'user' => $user,
            'account' => $account,
            'store' => $store,
            'stores' => $stores->getAllStores(),
            'transactions' => $plugin->getLedger()->getTransactions([
                'userId' => $userId,
                'storeId' => $store->id,
            ], 100),
            'lots' => $plugin->getLedger()->getSpendableLots($userId, $store->id),
            'creditLots' => $plugin->isPro()
                ? $plugin->getLedger()->getSpendableLots($userId, $store->id, Rule::CURRENCY_CREDIT)
                : [],
            'coupons' => $plugin->getCoupons()->getCoupons([
                'userId' => $userId,
                'storeId' => $store->id,
            ], 50),
            'settings' => $plugin->getSettings(),
            'plugin' => $plugin,
            'canAdjust' => Craft::$app->getUser()->checkPermission('pointz-adjustBalances'),
            'title' => $user->getUiLabel(),
        ]);
    }

    /**
     * A manual grant or deduction. Both go through the ledger, so the value that lands here
     * expires and reverses exactly like an earned one.
     */
    public function actionAdjust(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pointz-adjustBalances');

        $plugin = Plugin::getInstance();
        $userId = (int)$this->request->getRequiredBodyParam('userId');
        $storeId = (int)$this->request->getRequiredBodyParam('storeId');
        $currency = (string)$this->request->getBodyParam('currency', Rule::CURRENCY_POINTS);
        $amount = (float)$this->request->getRequiredBodyParam('amount');
        $note = $this->request->getBodyParam('note') ?: null;

        if ($amount == 0.0) {
            return $this->asFailure(Craft::t('pointz', 'Enter an amount to move.'));
        }

        try {
            if ($amount > 0) {
                $plugin->getGrants()->grant($userId, $storeId, $currency, $amount, $note);
                $message = Craft::t('pointz', 'Balance increased.');
            } else {
                $plugin->getGrants()->deduct($userId, $storeId, $currency, abs($amount), $note);
                $message = Craft::t('pointz', 'Balance reduced.');
            }
        } catch (PointzException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess($message, [], UrlHelper::cpUrl("pointz/balances/$userId", ['store' => $this->request->getBodyParam('storeHandle')]));
    }

    /**
     * Rebuilds a customer's cached balance from their lots. The support answer to "the number
     * looks wrong", and harmless to run.
     */
    public function actionRecalculate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pointz-adjustBalances');

        $userId = (int)$this->request->getRequiredBodyParam('userId');
        $storeId = (int)$this->request->getRequiredBodyParam('storeId');

        $account = Plugin::getInstance()->getAccounts()->refresh($userId, $storeId);

        return $this->asSuccess(Craft::t('pointz', 'Balance rebuilt from the ledger.'), [
            'pointsBalance' => $account->pointsBalance,
            'creditBalance' => $account->creditBalance,
        ]);
    }

    /**
     * Takes back a live coupon. Points a threshold spent on it are not returned on their own —
     * grant them back here if they are owed.
     */
    public function actionRevokeCoupon(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('pointz-adjustBalances');

        $coupons = Plugin::getInstance()->getCoupons();
        $coupon = $coupons->getCouponById((int)$this->request->getRequiredBodyParam('couponId'));

        if ($coupon === null) {
            throw new NotFoundHttpException('Coupon not found');
        }

        if (!$coupons->revoke($coupon)) {
            return $this->asFailure(Craft::t('pointz', 'Only an active coupon can be revoked.'));
        }

        return $this->asSuccess(Craft::t('pointz', 'Coupon revoked.'));
    }
}
