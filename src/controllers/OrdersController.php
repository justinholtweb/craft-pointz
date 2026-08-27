<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Order-side actions from the control panel: paying a refund as store credit, and re-running an
 * order's earnings after a rule has been fixed.
 */
class OrdersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('pointz-adjustBalances');

        if (!Plugin::commerceIsReady()) {
            throw new ForbiddenHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    /**
     * Issues store credit against an order. Commerce is not told anything — this is a credit note
     * the shop chooses to give, and the gateway refund stays a separate, deliberate decision in
     * Commerce's own screens.
     */
    public function actionRefundToCredit(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $order = $this->_order();
        $amount = (float)$this->request->getRequiredBodyParam('amount');
        $note = $this->request->getBodyParam('note') ?: null;

        if ($amount <= 0) {
            return $this->asFailure(Craft::t('pointz', 'Enter an amount to credit.'));
        }

        try {
            $transaction = Plugin::getInstance()->getGrants()->refundToCredit($order, $amount, $note);
        } catch (PointzException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('pointz', 'Store credit issued.'), [
            'transactionId' => $transaction->id,
            'balanceAfter' => $transaction->balanceAfter,
        ]);
    }

    /**
     * Runs the earning rules over an order that never earned — a rule switched on after the fact,
     * or an order that completed while earning was off.
     */
    public function actionAward(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $order = $this->_order();
        $written = Plugin::getInstance()->getEarning()->awardOrder($order);

        if (!$written) {
            return $this->asFailure(Craft::t('pointz', 'Nothing to award — this order has already earned, or no rule matches it.'));
        }

        return $this->asSuccess(Craft::t('pointz', 'Order awarded.'), [
            'transactions' => count($written),
        ]);
    }

    private function _order(): Order
    {
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException('Order not found');
        }

        return $order;
    }
}
