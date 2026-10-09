<?php

namespace justinholtweb\pointz\gql\resolvers;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Error\UserError;
use justinholtweb\pointz\models\Account;
use justinholtweb\pointz\models\Award;
use justinholtweb\pointz\models\Lot;
use justinholtweb\pointz\models\Quote;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

/**
 * Everything the Pointz GraphQL fields resolve to — one customer's data, and only theirs.
 *
 * **Who is asking is the Craft session, nothing else.** No field takes a user id or an email: a
 * GraphQL token is shared by every caller of its schema (the public schema has no token at all), so
 * an argument naming the customer would let anyone read anyone's balance. A request with no signed-in
 * user — a guest, or a server-to-server call with only a token — gets nulls and empty lists.
 *
 * Carts are the same: a `cartNumber` is accepted so a headless storefront can name its cart, but a
 * cart is only ever read or changed when its customer *is* the signed-in user. Commerce makes the
 * owner of an email the customer of a guest cart that types it in, which is why this is the
 * signed-in user and not just the cart's customer — the rule `Redemption::spenderId()` follows.
 *
 * Every answer here depends on the visitor, which Craft's GraphQL result cache does not key on.
 * `Plugin::_registerGql()` switches that cache off for any document naming a Pointz field — see
 * {@see isPointzQuery()}.
 */
class Customer
{
    /** The most ledger rows one query may ask for. */
    public const MAX_LEDGER = 100;

    /** The largest quantity an earning preview will price. */
    public const MAX_QTY = 10000;

    /**
     * Whether a GraphQL document names a Pointz field. A field cannot be aliased or fragmented
     * without its name appearing in the text, so this cannot be dodged — and over-matching only
     * costs a cache miss.
     */
    public static function isPointzQuery(string $query): bool
    {
        return (bool)preg_match('/\bpointz[A-Z]/', $query);
    }

    public static function balance(): ?float
    {
        return self::_account()?->pointsBalance;
    }

    public static function creditBalance(): ?float
    {
        return self::_account()?->creditBalance;
    }

    public static function pendingBalance(): ?float
    {
        return self::_account()?->pendingPoints;
    }

    public static function account(): ?Account
    {
        return self::_account();
    }

    /**
     * @return Transaction[]
     */
    public static function ledger(mixed $root, array $args): array
    {
        $userId = self::userId();
        $storeId = self::storeId();

        if ($userId === null || $storeId === null) {
            return [];
        }

        $criteria = ['userId' => $userId, 'storeId' => $storeId];
        $currency = $args['currency'] ?? null;

        if ($currency !== null) {
            if (!in_array($currency, [Rule::CURRENCY_POINTS, Rule::CURRENCY_CREDIT], true)) {
                throw new UserError(Craft::t('pointz', '`currency` must be `points` or `credit`.'));
            }

            $criteria['currency'] = $currency;
        }

        $limit = max(1, min(self::MAX_LEDGER, (int)($args['limit'] ?? 25)));
        $offset = max(0, (int)($args['offset'] ?? 0));

        return Plugin::getInstance()->getLedger()->getTransactions($criteria, $limit, $offset);
    }

    /**
     * @return Lot[]
     */
    public static function expiring(mixed $root, array $args): array
    {
        $userId = self::userId();
        $storeId = self::storeId();

        if ($userId === null || $storeId === null) {
            return [];
        }

        $days = isset($args['days']) ? max(0, (int)$args['days']) : null;

        return Plugin::getInstance()->getLifecycle()->getExpiringLots($userId, $storeId, $days);
    }

    /**
     * What a purchasable is worth, for a badge on a product page. The one field a guest gets an
     * answer from — prices are public — though a signed-in customer's rules are applied to them.
     */
    public static function earnFor(mixed $root, array $args): ?Award
    {
        $storeId = self::storeId();

        if ($storeId === null) {
            return null;
        }

        $purchasable = Craft::$app->getElements()->getElementById((int)$args['purchasableId']);

        if (!$purchasable instanceof Purchasable || !self::_isReadable($purchasable)) {
            return null;
        }

        $qty = (float)($args['qty'] ?? 1);
        $qty = $qty > 0 ? min($qty, self::MAX_QTY) : 1.0;

        return Plugin::getInstance()->getEarning()->previewPurchasable(
            $purchasable,
            $qty,
            $storeId,
            Craft::$app->getUser()->getIdentity()
        );
    }

    public static function quote(mixed $root, array $args): ?Quote
    {
        $cart = self::cart($args['cartNumber'] ?? null);

        if ($cart === null) {
            return null;
        }

        return Plugin::getInstance()->getRedemption()->quote(
            $cart,
            isset($args['points']) ? max(0, (float)$args['points']) : null,
            isset($args['credit']) ? max(0, (float)$args['credit']) : null
        );
    }

    public static function willEarn(mixed $root, array $args): ?Award
    {
        $cart = self::cart($args['cartNumber'] ?? null);

        if ($cart === null) {
            return null;
        }

        return Plugin::getInstance()->getEarning()->evaluateOrder($cart, true);
    }

    /**
     * `pointzRedeem` — `pointz/cart/redeem`, over GraphQL.
     *
     * @return array{success: bool, message: string, quote: ?Quote}
     */
    public static function redeem(mixed $root, array $args): array
    {
        self::_requireCsrf();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $cart = self::cart($args['cartNumber'] ?? null);

        // Signed in, as the cart's own customer — exactly what the action requires.
        if ($cart === null || !$cart->id) {
            return [
                'success' => false,
                'message' => Craft::t('pointz', 'Sign in to spend your {label}.', ['label' => $settings->pointsLabelPlural]),
                'quote' => null,
            ];
        }

        $redemption = $plugin->getRedemption();
        $points = isset($args['points']) ? (float)$args['points'] : null;
        $credit = isset($args['credit']) ? (float)$args['credit'] : null;

        // "Everything you have" is what a checkout button actually means.
        if (!empty($args['allPoints'])) {
            $points = $redemption->quote($cart)->maxPoints;
        }

        if (!empty($args['allCredit'])) {
            $credit = $redemption->quote($cart)->maxCredit;
        }

        $quote = $redemption->setIntent($cart, $points, $credit, self::userId());
        Craft::$app->getElements()->saveElement($cart, false);

        if ($quote->getIsEmpty() && $quote->notices) {
            return ['success' => false, 'message' => $quote->notices[0], 'quote' => $quote];
        }

        return ['success' => true, 'message' => Craft::t('pointz', 'Applied to your order.'), 'quote' => $quote];
    }

    /**
     * `pointzRemoveRedemption` — `pointz/cart/remove`, over GraphQL.
     *
     * @return array{success: bool, message: string, quote: ?Quote}
     */
    public static function removeRedemption(mixed $root, array $args): array
    {
        self::_requireCsrf();

        $cart = self::cart($args['cartNumber'] ?? null);

        if ($cart === null || !$cart->id) {
            return [
                'success' => false,
                'message' => Craft::t('pointz', 'Sign in to spend your {label}.', ['label' => Plugin::getInstance()->getSettings()->pointsLabelPlural]),
                'quote' => null,
            ];
        }

        $redemption = Plugin::getInstance()->getRedemption();
        $redemption->clearIntent($cart->id);
        $cart->recalculate();
        Craft::$app->getElements()->saveElement($cart, false);

        return ['success' => true, 'message' => Craft::t('pointz', 'Removed from your order.'), 'quote' => $redemption->quote($cart)];
    }

    /**
     * The signed-in user, and nobody else.
     */
    public static function userId(): ?int
    {
        return Craft::$app->getUser()->getIdentity()?->id;
    }

    public static function storeId(): ?int
    {
        if (!Plugin::commerceIsReady()) {
            return null;
        }

        return Commerce::getInstance()->getStores()->getCurrentStore()->id;
    }

    /**
     * The signed-in customer's open cart: the one `cartNumber` names, or the session's own. Null
     * unless the cart's customer is the signed-in user.
     */
    public static function cart(?string $number): ?Order
    {
        $userId = self::userId();

        if ($userId === null || !Plugin::commerceIsReady()) {
            return null;
        }

        if ($number !== null && $number !== '') {
            // An exact match on the whole number — never a partial or wildcard lookup.
            if (!preg_match('/^[a-zA-Z0-9]{1,64}$/', $number)) {
                return null;
            }

            $cart = Order::find()->number($number)->isCompleted(false)->status(null)->one();
        } elseif (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        } else {
            $cart = Commerce::getInstance()->getCarts()->getCart();
        }

        if (!$cart instanceof Order || $cart->isCompleted || $cart->getCustomerId() !== $userId) {
            return null;
        }

        return $cart;
    }

    private static function _account(): ?Account
    {
        $userId = self::userId();
        $storeId = self::storeId();

        if ($userId === null || $storeId === null) {
            return null;
        }

        // A customer who has never earned has no row yet, but does have a balance: zero.
        return Plugin::getInstance()->getAccounts()->getAccount($userId, $storeId)
            ?? new Account(['userId' => $userId, 'storeId' => $storeId]);
    }

    /**
     * Live, and inside the product types the schema may read. Anything else does not exist as far
     * as the caller is concerned.
     */
    private static function _isReadable(Purchasable $purchasable): bool
    {
        if (!$purchasable->enabled || $purchasable->getEnabledForSite() === false) {
            return false;
        }

        if ($purchasable instanceof Variant) {
            $product = $purchasable->getProduct();

            if ($product === null || !$product->enabled || $product->getEnabledForSite() === false) {
                return false;
            }

            return GqlHelper::isSchemaAwareOf('productTypes.' . $product->getType()->uid);
        }

        return true;
    }

    /**
     * The session is the credential, so a mutation is a cross-site request forgery target in a way
     * a token-authenticated one is not — Craft turns CSRF validation off for its `/api` action.
     * The `pointz/cart/redeem` action has Craft's own check; this is the same check, by hand: a
     * POST carrying the session's token (in `X-CSRF-Token`, or the body's `CRAFT_CSRF_TOKEN`).
     */
    private static function _requireCsrf(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !Craft::$app->getConfig()->getGeneral()->enableCsrfProtection) {
            return;
        }

        /** @var \craft\web\Request $request */
        if (!$request->getIsPost() || !$request->validateCsrfToken()) {
            throw new UserError(Craft::t('pointz', 'Send this mutation as a POST with the session’s CSRF token in an X-CSRF-Token header.'));
        }
    }
}
