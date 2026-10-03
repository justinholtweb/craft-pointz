<?php
/**
 * Whose balance a cart can spend — checked over HTTP, the way a shopper would try it.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/tests/integration/security.php
 *
 * Commerce makes whoever owns an email the customer of a guest cart that types it in. Until 5.0.1
 * that was all Pointz looked at, so a guest could put a registered customer's email on their cart,
 * read that customer's balances from the quote action, and spend their points and store credit.
 * Each refusal here is paired with the same request made by the customer, signed in, so a pass
 * means "refused", not "broken".
 *
 * Needs Commerce, Pointz Pro with redemption enabled (the harness default), and a product type.
 * Self-cleaning: users, products, carts and every ledger row they made are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\pointz\adjusters\Redemption as RedemptionAdjuster;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'pz-' . bin2hex(random_bytes(12));
$users = [];
$elements = [];

register_shutdown_function(function() use (&$users, &$elements) {
    $userIds = array_map(fn(User $user) => $user->id, $users);

    foreach (Order::find()->customerId($userIds)->status(null)->all() as $order) {
        Craft::$app->getElements()->deleteElement($order, true);
    }

    // Accounts, transactions and lots cascade from the user.
    foreach (array_merge($elements, $users) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
});

// A registered customer with a balance worth stealing.
$victim = new User(['username' => "pointz-victim-$run", 'email' => "pointz-victim-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($victim, false);
Craft::$app->getUsers()->activateUser($victim);
$users[] = $victim;

$plugin->getLedger()->credit($victim->id, $storeId, Rule::CURRENCY_POINTS, 5000, ['kind' => Transaction::KIND_ADJUST, 'note' => 'security.php']);
$plugin->getLedger()->credit($victim->id, $storeId, Rule::CURRENCY_CREDIT, 40, ['kind' => Transaction::KIND_ADJUST, 'note' => 'security.php']);

$product = new Product(['typeId' => Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id, 'title' => "Pointz security $run", 'enabled' => true]);
$variant = new Variant(['sku' => "PZSEC-$run", 'basePrice' => 50, 'isDefault' => true]);
$product->setVariants([$variant]);
Craft::$app->getElements()->saveElement($product);
$elements[] = $product;
$variantId = $product->getVariants()[0]->id;

$balance = function(string $currency) use ($plugin, $victim, $storeId): float {
    $plugin->getAccounts()->clearMemo();
    $account = $plugin->getAccounts()->getAccount($victim->id, $storeId);

    return (float)($currency === Rule::CURRENCY_POINTS ? $account?->pointsBalance : $account?->creditBalance);
};

/**
 * A shopper's browser: a cookie jar, a fresh CSRF token per post, JSON in and out.
 */
function shopper(): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $post = static fn(string $action, array $params) => json_decode((string)$http->post("index.php?p=actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ])->getBody(), true) ?? [];
    $get = static fn(string $action, array $query = []) => json_decode((string)$http->get("index.php?p=actions/$action&" . http_build_query($query), ['headers' => $json])->getBody(), true) ?? [];

    return [$post, $get];
}

$cartAdjustments = function(?string $number): array {
    $order = $number ? Order::find()->number($number)->status(null)->one() : null;

    return $order ? array_values(array_filter($order->getAdjustments(), fn($a) => in_array($a->type, RedemptionAdjuster::adjustmentTypes(), true))) : [];
};

// -------------------------------------------------------------------------------------------
echo "\nA guest who types a customer's email\n";

[$guestPost, $guestGet] = shopper();
$guestCart = $guestPost('commerce/cart/update-cart', ['purchasableId' => $variantId, 'qty' => 1, 'email' => $victim->email]);
$guestNumber = $guestCart['cart']['number'] ?? null;

check('Commerce really does make the customer the guest cart’s owner (the premise)', function() use ($guestNumber, $victim) {
    $order = $guestNumber ? Order::find()->number($guestNumber)->status(null)->one() : null;

    return $order?->getCustomerId() === $victim->id ?: 'customer ' . var_export($order?->getCustomerId(), true);
});

check('the quote doesn’t show the guest the customer’s balances', function() use ($guestGet) {
    $quote = $guestGet('pointz/cart/quote')['quote'] ?? [];

    return ($quote['pointsBalance'] ?? null) == 0 && ($quote['creditBalance'] ?? null) == 0 && ($quote['maxPoints'] ?? null) == 0
        ?: json_encode($quote);
});

check('the guest can’t apply the customer’s points', function() use ($guestPost, $guestNumber, $cartAdjustments) {
    $response = $guestPost('pointz/cart/redeem', ['points' => 'max']);

    return ($response['success'] ?? null) === false && $cartAdjustments($guestNumber) === [] ?: json_encode($response);
});

check('…or their store credit', function() use ($guestPost, $guestNumber, $cartAdjustments) {
    $response = $guestPost('pointz/cart/redeem', ['credit' => 'max']);

    return ($response['success'] ?? null) === false && $cartAdjustments($guestNumber) === [] ?: json_encode($response);
});

check('…and records no intent for the cart', function() use ($guestNumber) {
    $order = $guestNumber ? Order::find()->number($guestNumber)->status(null)->one() : null;
    $row = $order ? (new Query())->from(Table::CART_REDEMPTIONS)->where(['orderId' => $order->id])->one() : null;

    return $row === null || ((float)$row['points'] == 0 && (float)$row['credit'] == 0) ?: json_encode($row);
});

check('the customer’s balances are untouched', fn() => $balance(Rule::CURRENCY_POINTS) == 5000 && $balance(Rule::CURRENCY_CREDIT) == 40 ?: 'points ' . $balance(Rule::CURRENCY_POINTS) . ', credit ' . $balance(Rule::CURRENCY_CREDIT));

// -------------------------------------------------------------------------------------------
echo "\nThe customer, signed in\n";

[$ownPost, $ownGet] = shopper();
$signIn = $ownPost('users/login', ['loginName' => $victim->username, 'password' => $password]);
$ownCart = $ownPost('commerce/cart/update-cart', ['purchasableId' => $variantId, 'qty' => 1]);
$ownNumber = $ownCart['cart']['number'] ?? null;

check('sees their own balances in the quote', function() use ($ownGet, $signIn) {
    $quote = $ownGet('pointz/cart/quote')['quote'] ?? [];

    return ($quote['pointsBalance'] ?? null) == 5000 && ($quote['creditBalance'] ?? null) == 40 ?: 'sign-in ' . json_encode($signIn) . ' quote ' . json_encode($quote);
});

check('can apply their points, and the cart is discounted', function() use ($ownPost, $ownNumber, $cartAdjustments) {
    $response = $ownPost('pointz/cart/redeem', ['points' => 1000]);
    $adjustments = $cartAdjustments($ownNumber);

    return ($response['success'] ?? null) === true && count($adjustments) === 1 && $adjustments[0]->amount == -10.0 ?: json_encode($response);
});

check('the intent records who asked', function() use ($ownNumber, $victim) {
    $order = Order::find()->number($ownNumber)->status(null)->one();
    $row = (new Query())->from(Table::CART_REDEMPTIONS)->where(['orderId' => $order->id])->one();

    return (int)($row['userId'] ?? 0) === $victim->id ?: json_encode($row);
});

// -------------------------------------------------------------------------------------------
echo "\nCompletion\n";

check('a redemption the customer made is still honoured when nobody is signed in (a webhook)', function() use ($ownNumber, $balance) {
    $order = Order::find()->number($ownNumber)->status(null)->one();
    $before = $balance(Rule::CURRENCY_POINTS);
    $order->markAsComplete();

    return $balance(Rule::CURRENCY_POINTS) == $before - 1000 ?: "points $before → " . $balance(Rule::CURRENCY_POINTS);
});

check('a redemption someone else made is never charged to the customer', function() use ($plugin, $victim, $variantId, $storeId, $balance, $run, &$users) {
    // The cart's customer changed after the discount was worked out: the intent belongs to
    // someone else. Nothing may be taken from the customer's balance for it.
    $other = new User(['username' => "pointz-other-$run", 'email' => "pointz-other-$run@example.com"]);
    Craft::$app->getElements()->saveElement($other, false);
    $users[] = $other;

    $order = new Order(['storeId' => $storeId, 'orderSiteId' => Craft::$app->getSites()->getPrimarySite()->id]);
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setCustomer($victim);
    Craft::$app->getElements()->saveElement($order, false);
    $order->setLineItems([Commerce::getInstance()->getLineItems()->createLineItem($order, $variantId, [], 1)]);
    Craft::$app->getElements()->saveElement($order, false);

    $plugin->getRedemption()->setIntent($order, 500, 0, $victim->id);
    Craft::$app->getElements()->saveElement($order, false);
    Db::update(Table::CART_REDEMPTIONS, ['userId' => $other->id], ['orderId' => $order->id]);

    $before = $balance(Rule::CURRENCY_POINTS);
    $written = $plugin->getRedemption()->commitOrder($order);

    return $written === [] && $balance(Rule::CURRENCY_POINTS) == $before && $order->getNotices() !== []
        ? true
        : 'written ' . count($written) . ", points $before → " . $balance(Rule::CURRENCY_POINTS);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
