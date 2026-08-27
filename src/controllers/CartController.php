<?php

namespace justinholtweb\pointz\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\pointz\models\Quote;
use justinholtweb\pointz\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The front-end half: a customer choosing to spend points on their cart.
 *
 * Nothing here spends anything — it records an intent, and the adjuster re-clamps that intent on
 * every recalculation. A customer can post any number they like; the worst that happens is a
 * smaller discount and a notice saying why.
 */
class CartController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::commerceIsReady()) {
            throw new BadRequestHttpException('Craft Commerce is not installed.');
        }

        return true;
    }

    /**
     * Applies points, store credit, or both.
     */
    public function actionRedeem(): ?Response
    {
        $this->requirePostRequest();

        $cart = Commerce::getInstance()->getCarts()->getCart();
        $settings = Plugin::getInstance()->getSettings();

        if ($cart->getCustomerId() === null) {
            return $this->_fail(Craft::t('pointz', 'Sign in to spend your {label}.', [
                'label' => $settings->pointsLabelPlural,
            ]), $cart->id ? Plugin::getInstance()->getRedemption()->quote($cart) : null);
        }

        $points = $this->request->getBodyParam('points');
        $credit = $this->request->getBodyParam('credit');

        // "Everything you have" is what a checkout button actually means.
        if ($points === 'max') {
            $points = Plugin::getInstance()->getRedemption()->quote($cart)->maxPoints;
        }

        if ($credit === 'max') {
            $credit = Plugin::getInstance()->getRedemption()->quote($cart)->maxCredit;
        }

        $quote = Plugin::getInstance()->getRedemption()->setIntent(
            $cart,
            $points === null ? null : (float)$points,
            $credit === null ? null : (float)$credit
        );

        Craft::$app->getElements()->saveElement($cart, false);

        if ($quote->getIsEmpty() && $quote->notices) {
            return $this->_fail($quote->notices[0], $quote);
        }

        return $this->_ok(Craft::t('pointz', 'Applied to your order.'), $quote);
    }

    /**
     * Takes the redemption back off.
     */
    public function actionRemove(): ?Response
    {
        $this->requirePostRequest();

        $cart = Commerce::getInstance()->getCarts()->getCart();

        Plugin::getInstance()->getRedemption()->clearIntent($cart->id);
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        return $this->_ok(
            Craft::t('pointz', 'Removed from your order.'),
            Plugin::getInstance()->getRedemption()->quote($cart)
        );
    }

    /**
     * A quote without applying anything — for a slider or a live preview.
     */
    public function actionQuote(): Response
    {
        $this->requireAcceptsJson();

        $cart = Commerce::getInstance()->getCarts()->getCart();
        $points = $this->request->getParam('points');
        $credit = $this->request->getParam('credit');

        $quote = Plugin::getInstance()->getRedemption()->quote(
            $cart,
            $points === null ? null : (float)$points,
            $credit === null ? null : (float)$credit
        );

        return $this->asJson(['quote' => $this->_quoteArray($quote)]);
    }

    private function _ok(string $message, ?Quote $quote): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'message' => $message,
                'quote' => $quote ? $this->_quoteArray($quote) : null,
            ]);
        }

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }

    private function _fail(string $message, ?Quote $quote): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'message' => $message,
                'quote' => $quote ? $this->_quoteArray($quote) : null,
            ]);
        }

        $this->setFailFlash($message);
        Craft::$app->getUrlManager()->setRouteParams(['pointzQuote' => $quote]);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function _quoteArray(Quote $quote): array
    {
        return [
            'points' => $quote->points,
            'pointsValue' => $quote->pointsValue,
            'credit' => $quote->credit,
            'maxPoints' => $quote->maxPoints,
            'maxCredit' => $quote->maxCredit,
            'pointsBalance' => $quote->pointsBalance,
            'creditBalance' => $quote->creditBalance,
            'base' => $quote->base,
            'cap' => $quote->cap,
            'totalDiscount' => $quote->getTotalDiscount(),
            'wasClamped' => $quote->getWasClamped(),
            'notices' => $quote->notices,
        ];
    }
}
