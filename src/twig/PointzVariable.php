<?php

namespace justinholtweb\pointz\twig;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use justinholtweb\pointz\models\Account;
use justinholtweb\pointz\models\Award;
use justinholtweb\pointz\models\Quote;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Settings;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * `craft.pointz` — everything a front-end template needs.
 *
 * Every method defaults to the logged-in customer and the current cart, because that is what a
 * template almost always means, and takes them explicitly when it does not.
 *
 * Nothing here writes. A preview cannot spend a point, a quote cannot move a balance, and no
 * amount of template refreshing can award anything twice.
 */
class PointzVariable
{
    /**
     * The customer's spendable points.
     */
    public function balance(User|int|null $user = null, ?int $storeId = null): float
    {
        $account = $this->account($user, $storeId);

        return $account?->pointsBalance ?? 0.0;
    }

    /**
     * The customer's store credit.
     */
    public function creditBalance(User|int|null $user = null, ?int $storeId = null): float
    {
        $account = $this->account($user, $storeId);

        return $account?->creditBalance ?? 0.0;
    }

    /**
     * Points earned but not yet released — held after a purchase, or waiting on an order status.
     */
    public function pendingBalance(User|int|null $user = null, ?int $storeId = null): float
    {
        $account = $this->account($user, $storeId);

        return $account?->pendingPoints ?? 0.0;
    }

    public function account(User|int|null $user = null, ?int $storeId = null): ?Account
    {
        $userId = $this->_userId($user);
        $storeId ??= $this->_storeId();

        if ($userId === null || $storeId === null) {
            return null;
        }

        return Plugin::getInstance()->getAccounts()->getAccount($userId, $storeId);
    }

    /**
     * The customer's own history, newest first.
     *
     * @return Transaction[]
     */
    public function ledger(int $limit = 25, int $offset = 0, User|int|null $user = null, ?int $storeId = null): array
    {
        $userId = $this->_userId($user);
        $storeId ??= $this->_storeId();

        if ($userId === null || $storeId === null) {
            return [];
        }

        return Plugin::getInstance()->getLedger()->getTransactions([
            'userId' => $userId,
            'storeId' => $storeId,
        ], $limit, $offset);
    }

    /**
     * What redeeming would come to on this cart — including why it was clamped, if it was.
     *
     * Pass `points` to quote a hypothetical without changing anything; pass nothing to quote what
     * the cart is already carrying.
     */
    public function quote(?Order $cart = null, ?float $points = null, ?float $credit = null): ?Quote
    {
        $cart ??= $this->cart();

        if ($cart === null) {
            return null;
        }

        return Plugin::getInstance()->getRedemption()->quote($cart, $points, $credit);
    }

    /**
     * The most points this cart could take right now.
     */
    public function maxRedeemable(?Order $cart = null): float
    {
        return $this->quote($cart)?->maxPoints ?? 0.0;
    }

    /**
     * What the cart would earn if it were completed now — the "you'll earn" line.
     */
    public function willEarn(?Order $cart = null): ?Award
    {
        $cart ??= $this->cart();

        if ($cart === null) {
            return null;
        }

        return Plugin::getInstance()->getEarning()->evaluateOrder($cart, true);
    }

    /**
     * What a product is worth, for a badge on a product page.
     */
    public function earnFor(mixed $purchasable, float $qty = 1, ?int $storeId = null): Award
    {
        return Plugin::getInstance()->getEarning()->previewPurchasable(
            $purchasable,
            $qty,
            $storeId ?? $this->_storeId(),
            Craft::$app->getUser()->getIdentity()
        );
    }

    /**
     * Points as money.
     */
    public function value(float $points): float
    {
        return Plugin::getInstance()->getRedemption()->valueForPoints($points);
    }

    /**
     * Money as points.
     */
    public function points(float $value): float
    {
        return Plugin::getInstance()->getRedemption()->pointsForValue($value);
    }

    /**
     * The store's own word for a quantity of points — "1 point", "240 points", or whatever the
     * settings say instead.
     */
    public function label(float $amount): string
    {
        return Plugin::getInstance()->getSettings()->label($amount);
    }

    /**
     * A points figure formatted the way the control panel formats it.
     */
    public function format(float $points): string
    {
        return Plugin::getInstance()->getRedemption()->format($points);
    }

    /**
     * "240 points", ready to print.
     */
    public function formatted(float $points): string
    {
        $plugin = Plugin::getInstance();

        return $plugin->getRedemption()->format($points) . ' ' . $plugin->getSettings()->label($points);
    }

    public function formatMoney(float $amount, ?int $storeId = null): string
    {
        return Plugin::getInstance()->getRedemption()->formatMoney($amount, $storeId ?? $this->_storeId());
    }

    /**
     * The customer's value that expires inside the window, so a template can say "480 points
     * expire on 4 March".
     *
     * @return array<int, array{userId: int, storeId: int, currency: string, amount: float, dateExpires: string}>
     */
    public function expiring(?int $days = null, User|int|null $user = null, ?int $storeId = null): array
    {
        $userId = $this->_userId($user);
        $storeId ??= $this->_storeId();

        if ($userId === null || $storeId === null) {
            return [];
        }

        return array_values(array_filter(
            Plugin::getInstance()->getLifecycle()->getExpiringSoon($days),
            static fn(array $row) => (int)$row['userId'] === $userId && (int)$row['storeId'] === $storeId
        ));
    }

    /**
     * The current cart, without creating one. A visitor who has not started shopping should not
     * get a cart just because a template asked about their points.
     */
    public function cart(): ?Order
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getCarts()->getCart();
    }

    public function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    public function getIsPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    /**
     * The earning rules a shop might want to advertise: "double points on outdoor gear until
     * Sunday" is a rule, and a template should be able to read it rather than repeat it.
     *
     * @return Rule[]
     */
    public function activeRules(?int $storeId = null): array
    {
        $storeId ??= $this->_storeId();

        if ($storeId === null) {
            return [];
        }

        return Plugin::getInstance()->getRules()->getActiveRules($storeId, Rule::EVENT_ORDER);
    }

    private function _userId(User|int|null $user): ?int
    {
        if ($user instanceof User) {
            return $user->id;
        }

        if (is_int($user)) {
            return $user;
        }

        return Craft::$app->getUser()->getIdentity()?->id;
    }

    private function _storeId(): ?int
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        $stores = Commerce::getInstance()->getStores();

        return ($stores->getCurrentStore() ?? $stores->getPrimaryStore())?->id;
    }
}
