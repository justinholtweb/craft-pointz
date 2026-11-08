<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\pointz\models\Coupon;
use justinholtweb\pointz\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Every coupon Pointz has issued or adopted, across customers: the screen support opens when a
 * customer reads a code out over the phone. Revoking goes through `pointz/balances/revoke-coupon`,
 * the same action the balance page uses.
 */
class CouponsController extends Controller
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
        $coupons = $plugin->getCoupons();
        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();
        $status = $this->request->getParam('status') ?: null;
        $ruleId = $this->request->getParam('rule') ?: null;
        $search = trim((string)$this->request->getParam('search')) ?: null;
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;

        if ($status !== null && !array_key_exists($status, Coupon::statuses())) {
            $status = null;
        }

        $query = $coupons->getCouponsQuery([
            'storeId' => $store?->id,
            'status' => $status,
            'ruleId' => $ruleId,
            'search' => $search,
        ]);
        $total = (int)$query->count();

        $results = array_map(
            static fn(array $row) => new Coupon($row),
            (clone $query)->orderBy(['id' => SORT_DESC])->offset(($page - 1) * $perPage)->limit($perPage)->all()
        );

        // A page of codes is up to a hundred customers and orders: fetched in two queries, not two
        // hundred.
        $userIds = array_values(array_unique(array_filter(array_map(static fn(Coupon $coupon) => $coupon->userId, $results))));
        $orderIds = array_values(array_unique(array_filter(array_map(static fn(Coupon $coupon) => $coupon->orderId, $results))));
        $users = $userIds ? User::find()->id($userIds)->status(null)->indexBy('id')->all() : [];
        $orders = $orderIds ? Order::find()->id($orderIds)->status(null)->indexBy('id')->all() : [];

        // The rules that issue codes, for the filter and the summary. Codes from a deleted rule, and
        // imported ones, have no rule and are grouped together.
        $rules = [];

        foreach ($plugin->getRules()->getAllRules($store?->id) as $rule) {
            if ($rule->awardsCoupon() || $rule->couponDiscountId) {
                $rules[$rule->id] = $rule;
            }
        }

        $summary = $coupons->getSummary($store?->id);

        return $this->renderTemplate('pointz/coupons/_index', [
            'coupons' => $results,
            'summary' => $summary,
            'rules' => $rules,
            'users' => $users,
            'orders' => $orders,
            'store' => $store,
            'stores' => $stores->getAllStores(),
            'status' => $status,
            'ruleId' => $ruleId,
            'search' => $search,
            'statuses' => Coupon::statuses(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'canRevoke' => Craft::$app->getUser()->checkPermission('pointz-adjustBalances'),
            'plugin' => $plugin,
        ]);
    }

    /**
     * What the next sweep sends today: birthday rewards, expiry reminders and reminders after
     * issue, with the customers behind each. Loaded after the coupons index has rendered, because
     * finding today's birthdays walks every customer with one, as the sweep itself does.
     */
    public function actionDue(): Response
    {
        $plugin = Plugin::getInstance();
        $stores = Commerce::getInstance()->getStores();
        $storeHandle = $this->request->getParam('store');
        $store = $storeHandle ? $stores->getStoreByHandle($storeHandle) : $stores->getPrimaryStore();

        $reminders = $plugin->getCoupons()->getDueReminders(null, $store?->id);
        $users = [];
        $userIds = array_unique(array_map(static fn(array $due) => $due['coupon']->userId, $reminders));

        if ($userIds) {
            foreach (User::find()->id($userIds)->status(null)->all() as $user) {
                $users[$user->id] = $user;
            }
        }

        return $this->renderTemplate('pointz/coupons/_due', [
            'birthdays' => $plugin->getRewards()->getDueBirthdays(null, $store?->id),
            'expiry' => array_values(array_filter($reminders, static fn(array $due) => $due['kind'] === Coupon::REMIND_EXPIRY)),
            'issued' => array_values(array_filter($reminders, static fn(array $due) => $due['kind'] === Coupon::REMIND_ISSUED)),
            'users' => $users,
            'rules' => ArrayHelper::index($plugin->getRules()->getAllRules($store?->id), 'id'),
            'store' => $store,
            'plugin' => $plugin,
        ], View::TEMPLATE_MODE_CP);
    }
}
