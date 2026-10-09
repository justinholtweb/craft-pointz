<?php
/**
 * Pointz GraphQL checks — the signed-in customer's balances, ledger, expiring value and quotes,
 * and the redeem/remove mutations.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/tests/integration/graphql.php
 *
 * Every query goes through `Craft::$app->getGql()->executeQuery()` against an in-memory schema with
 * a fresh UID, so the schema components, the scopes and Craft's own result cache are the real ones.
 * Craft builds its GraphQL types once per process, so the script re-invokes itself once per schema
 * (no Pointz access / read / read + redeem) and adds up the children's counts. The fixtures are made
 * once, here in the parent, and handed down as JSON.
 *
 * **The point of the suite** is the leak Craft's GraphQL cache makes easy: results are cached under
 * the schema, the query text and its variables — nothing about the visitor — and every Pointz field
 * answers for the visitor. The read schema runs with caching ON and asks the same query as customer
 * A, customer B, a guest and A again.
 *
 * Self-cleaning: the users (whose deletion cascades their ledger), the product, the cart and the
 * rule are removed in a `finally`. Settings and the edition are changed in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\services\Gql;
use justinholtweb\pointz\gql\mutations\Pointz as PointzMutations;
use justinholtweb\pointz\gql\queries\Pointz as PointzQueries;
use justinholtweb\pointz\gql\resolvers\Customer;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;
use yii\base\Event;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function schema(array $scope): GqlSchema
{
    // A fresh UID every time: Craft's result cache is keyed on it, so a reused one could answer a
    // "denied" check from an earlier run's cache.
    return new GqlSchema(['name' => 'Pointz test', 'uid' => StringHelper::UUID(), 'scope' => $scope]);
}

function gql(GqlSchema $schema, string $query, array $variables = []): array
{
    return Craft::$app->getGql()->executeQuery($schema, $query, $variables, null, true);
}

function errors(array $result): string
{
    return implode(' | ', array_column($result['errors'] ?? [], 'message'));
}

function as_user(?User $user): void
{
    Craft::$app->getUser()->setIdentity($user);
    Plugin::getInstance()->getAccounts()->clearMemo();
}

$plugin = Plugin::getInstance();
$storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
$mode = $argv[1] ?? 'parent';

// ─────────────────────────────────────────────────────────────────────────────────────────────
// The children: one schema each.
// ─────────────────────────────────────────────────────────────────────────────────────────────

if ($mode !== 'parent') {
    $fixture = Json::decode((string)getenv('POINTZ_GQL_FIXTURE'));
    $a = User::find()->id($fixture['a'])->status(null)->one();
    $b = User::find()->id($fixture['b'])->status(null)->one();
    $variant = Variant::find()->id($fixture['variant'])->status(null)->one();
    $hiddenVariant = Variant::find()->id($fixture['hiddenVariant'])->status(null)->one();
    $cartNumber = $fixture['cart'];
    $productTypeUid = $fixture['productTypeUid'];

    $settings = $plugin->getSettings();
    $settings->expiryEnabled = true;
    $settings->redemptionEnabled = true;
    $settings->minPointsToRedeem = 0;

    $general = Craft::$app->getConfig()->getGeneral();
    $general->enableGraphqlCaching = true;

    $intent = static fn() => $plugin->getRedemption()->getIntent((int)Order::find()->number($cartNumber)->status(null)->one()->id);
}

if ($mode === 'noaccess') {
    section('A schema without the Pointz component');

    check('the schema components are registered: Pointz read under queries, redeem under mutations', function() {
        $components = Craft::$app->getGql()->getAllSchemaComponents();

        return isset($components['queries']['Pointz'][PointzQueries::SCOPE . ':read'])
            && isset($components['mutations']['Pointz'][PointzQueries::SCOPE . ':' . PointzMutations::ACTION])
            ?: Json::encode(array_keys($components['queries'])) . ' / ' . Json::encode(array_keys($components['mutations']));
    });

    $schema = schema(["productTypes.$productTypeUid:read"]);

    check('a signed-in customer gets no Pointz field through it', function() use ($schema, $a) {
        as_user($a);
        $result = gql($schema, '{ pointzBalance pointzLedger { id } }');

        return !array_key_exists('pointzBalance', $result['data'] ?? []) && !array_key_exists('pointzLedger', $result['data'] ?? [])
            ?: Json::encode($result);
    });

    check('…and no Pointz mutation, and the cart is untouched', function() use ($schema, $a, $cartNumber, $intent) {
        as_user($a);
        $before = $intent();
        $result = gql($schema, 'mutation { pointzRedeem(cartNumber: "' . $cartNumber . '", points: 50) { success } }');

        return !array_key_exists('pointzRedeem', $result['data'] ?? []) && $intent() == $before
            ?: Json::encode($result);
    });
}

if ($mode === 'read') {
    $schema = schema([PointzQueries::SCOPE . ':read', "productTypes.$productTypeUid:read"]);
    $accountQuery = '{ pointzBalance pointzCreditBalance pointzAccount { pointsBalance lifetimePoints } pointzLedger { id amount kind kindLabel note } }';

    // A spy registered after the plugin's own handler: what the cache setting is by the time Craft
    // computes its cache key.
    $seen = [];
    Event::on(Gql::class, Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY, function($event) use (&$seen) {
        $seen[] = Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching;
    });

    section('Caching ON: one query text, three visitors');

    $answers = [];

    foreach ([['A', $a], ['B', $b], ['guest', null], ['A again', $a]] as [$who, $user]) {
        as_user($user);
        $answers[$who] = gql($schema, $accountQuery);
    }

    check('customer A sees A’s balance and only A’s ledger', function() use ($answers, $fixture) {
        $data = $answers['A']['data'] ?? [];
        $ids = array_column($data['pointzLedger'] ?? [], 'id');

        return ($data['pointzBalance'] ?? null) == 1234
            && ($data['pointzAccount']['pointsBalance'] ?? null) == 1234
            && $ids && !array_diff($ids, $fixture['aTransactions'])
            ?: Json::encode($answers['A']);
    });

    check('customer B, sending the same query next, gets B’s balance — not A’s cached one', function() use ($answers, $fixture) {
        $data = $answers['B']['data'] ?? [];
        $ids = array_column($data['pointzLedger'] ?? [], 'id');

        return ($data['pointzBalance'] ?? null) == 77
            && ($data['pointzAccount']['pointsBalance'] ?? null) == 77
            && !array_intersect($ids, $fixture['aTransactions'])
            && array_values($ids) == $fixture['bTransactions']
            ?: Json::encode($answers['B']);
    });

    check('a guest gets nulls and an empty ledger', function() use ($answers) {
        $data = $answers['guest']['data'] ?? null;

        return is_array($data) && !isset($answers['guest']['errors'])
            && $data['pointzBalance'] === null && $data['pointzCreditBalance'] === null
            && $data['pointzAccount'] === null && $data['pointzLedger'] === []
            ?: Json::encode($answers['guest']);
    });

    check('A again still gets A’s — the guest’s nulls were not cached either', function() use ($answers) {
        return ($answers['A again']['data']['pointzBalance'] ?? null) == 1234 ?: Json::encode($answers['A again']);
    });

    check('the guard switched caching off for those queries and put it back afterwards', function() use (&$seen, $schema) {
        $pointz = $seen;
        $seen = [];
        gql($schema, '{ __typename }');
        $other = $seen;

        return $pointz === [false, false, false, false] && $other === [true]
            && Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching === true
            ?: Json::encode(['pointz' => $pointz, 'other' => $other]);
    });

    check('the guard matches aliased and fragment uses, and leaves other documents alone', function() {
        return Customer::isPointzQuery('{ mine: pointzBalance }')
            && Customer::isPointzQuery("fragment F on Query { pointzLedger { id } }\nquery { ...F }")
            && Customer::isPointzQuery('mutation { pointzRedeem(points: 1) { success } }')
            && !Customer::isPointzQuery('{ entries(section: "pointz") { title } }')
            ?: 'matcher disagreed';
    });

    section('Ledger, expiring value, earning previews');

    check('the ledger takes limit, offset and currency, and caps limit at ' . Customer::MAX_LEDGER, function() use ($schema, $a, $fixture) {
        as_user($a);
        $one = gql($schema, '{ pointzLedger(limit: 1) { id } }')['data']['pointzLedger'] ?? null;
        $credit = gql($schema, '{ pointzLedger(currency: "credit") { id currency } }')['data']['pointzLedger'] ?? null;
        $huge = gql($schema, '{ pointzLedger(limit: 100000, offset: 0) { id } }');
        $bad = gql($schema, '{ pointzLedger(currency: "dollars") { id } }');

        return is_array($one) && count($one) === 1 && $credit === []
            && count($huge['data']['pointzLedger'] ?? []) === count($fixture['aTransactions'])
            && str_contains(errors($bad), 'currency')
            ?: Json::encode([$one, $credit, $huge, $bad]);
    });

    check('pointzExpiring lists A’s expiring lot with its date — and nothing for B or a guest', function() use ($schema, $a, $b) {
        $query = '{ pointzExpiring(days: 30) { currency amount remaining dateExpires } }';
        as_user($a);
        $mine = gql($schema, $query)['data']['pointzExpiring'] ?? null;
        as_user($b);
        $theirs = gql($schema, $query)['data']['pointzExpiring'] ?? null;
        as_user(null);
        $guest = gql($schema, $query)['data']['pointzExpiring'] ?? null;

        return is_array($mine) && count($mine) === 1 && $mine[0]['remaining'] == 234 && $mine[0]['dateExpires'] !== null
            && $theirs === [] && $guest === []
            ?: Json::encode([$mine, $theirs, $guest]);
    });

    check('pointzEarnFor matches what the Twig preview says, for a customer and for a guest', function() use ($schema, $a, $variant, $plugin, $storeId) {
        $query = '{ pointzEarnFor(purchasableId: ' . $variant->id . ', qty: 2) { points credit lines { ruleName amount } } }';
        as_user($a);
        $mine = gql($schema, $query)['data']['pointzEarnFor'] ?? null;
        $expected = $plugin->getEarning()->previewPurchasable($variant, 2, $storeId, $a)->getPoints();
        as_user(null);
        $guest = gql($schema, $query)['data']['pointzEarnFor'] ?? null;

        return $mine !== null && $mine['points'] > 0 && $mine['points'] == $expected && $guest !== null && $guest['points'] > 0
            ?: Json::encode([$mine, $expected, $guest]);
    });

    check('pointzEarnFor answers null for a disabled product and for a product type outside the schema', function() use ($schema, $a, $hiddenVariant, $variant) {
        as_user($a);
        $disabled = gql($schema, '{ x: pointzEarnFor(purchasableId: ' . $hiddenVariant->id . ') { points } }');
        $outside = gql(schema([PointzQueries::SCOPE . ':read']), '{ y: pointzEarnFor(purchasableId: ' . $variant->id . ') { points } }');

        return array_key_exists('x', $disabled['data'] ?? []) && $disabled['data']['x'] === null
            && array_key_exists('y', $outside['data'] ?? []) && $outside['data']['y'] === null
            ?: Json::encode([$disabled, $outside]);
    });

    section('Quotes: only the signed-in customer’s own cart');

    $quoteQuery = '{ pointzQuote(cartNumber: "' . $cartNumber . '", points: 100) { points pointsBalance maxPoints } pointzWillEarn(cartNumber: "' . $cartNumber . '") { points } }';

    check('A quotes A’s cart, with A’s balance', function() use ($schema, $a, $quoteQuery) {
        as_user($a);
        $data = gql($schema, $quoteQuery)['data'] ?? [];

        return ($data['pointzQuote']['pointsBalance'] ?? null) == 1234 && ($data['pointzQuote']['points'] ?? 0) > 0 && isset($data['pointzWillEarn'])
            ?: Json::encode($data);
    });

    check('B and a guest who know A’s cart number get nothing back', function() use ($schema, $b, $quoteQuery) {
        as_user($b);
        $theirs = gql($schema, $quoteQuery)['data'] ?? null;
        as_user(null);
        $guest = gql($schema, $quoteQuery)['data'] ?? null;

        return $theirs === ['pointzQuote' => null, 'pointzWillEarn' => null] && $guest === ['pointzQuote' => null, 'pointzWillEarn' => null]
            ?: Json::encode([$theirs, $guest]);
    });

    check('the read schema has no redeem mutation', function() use ($schema, $a, $cartNumber, $intent) {
        as_user($a);
        $before = $intent();
        $result = gql($schema, 'mutation { pointzRedeem(cartNumber: "' . $cartNumber . '", points: 50) { success } }');

        return !array_key_exists('pointzRedeem', $result['data'] ?? []) && $intent() == $before ?: Json::encode($result);
    });
}

if ($mode === 'redeem') {
    $schema = schema([PointzQueries::SCOPE . ':read', PointzQueries::SCOPE . ':' . PointzMutations::ACTION]);
    $redeem = fn(string $args) => 'mutation { pointzRedeem(cartNumber: "' . $cartNumber . '", ' . $args . ') { success message quote { points pointsBalance } } }';

    section('pointzRedeem / pointzRemoveRedemption');

    check('B cannot apply anything to A’s cart, and neither can a guest', function() use ($schema, $b, $redeem, $intent) {
        $before = $intent();
        as_user($b);
        $theirs = gql($schema, $redeem('points: 500'))['data']['pointzRedeem'] ?? null;
        as_user(null);
        $guest = gql($schema, $redeem('points: 500'))['data']['pointzRedeem'] ?? null;

        return $theirs['success'] === false && $theirs['quote'] === null && $guest['success'] === false && $intent() == $before
            ?: Json::encode([$theirs, $guest, $intent()]);
    });

    check('A applies 100 points: the intent is recorded as A’s, nothing is spent', function() use ($schema, $a, $redeem, $intent, $plugin, $storeId) {
        as_user($a);
        $result = gql($schema, $redeem('points: 100'))['data']['pointzRedeem'] ?? null;
        $now = $intent();
        $plugin->getAccounts()->clearMemo();

        return ($result['success'] ?? null) === true && $result['quote']['points'] == 100
            && $now['points'] == 100 && $now['userId'] === $a->id
            && $plugin->getAccounts()->getBalance($a->id, $storeId) == 1234
            ?: Json::encode([$result, $now]);
    });

    check('allPoints applies as much as the cart can take', function() use ($schema, $a, $redeem, $plugin, $cartNumber) {
        as_user($a);
        $result = gql($schema, $redeem('allPoints: true'))['data']['pointzRedeem'] ?? null;
        $max = $plugin->getRedemption()->quote(Order::find()->number($cartNumber)->status(null)->one(), 1.0E9)->maxPoints;

        return ($result['success'] ?? null) === true && $result['quote']['points'] == $max && $max > 100 ?: Json::encode([$result, $max]);
    });

    check('pointzRemoveRedemption takes it back off', function() use ($schema, $a, $cartNumber, $intent) {
        as_user($a);
        $result = gql($schema, 'mutation { pointzRemoveRedemption(cartNumber: "' . $cartNumber . '") { success quote { points } } }')['data']['pointzRemoveRedemption'] ?? null;

        return ($result['success'] ?? null) === true && $result['quote']['points'] == 0 && $intent()['points'] == 0
            ?: Json::encode([$result, $intent()]);
    });

    section('CSRF: the session is the credential, so a web request must carry its token');

    $console = Craft::$app->getRequest();
    $consoleResponse = Craft::$app->getResponse();
    $web = function(string $method, ?callable $prepare = null) {
        $_SERVER['REQUEST_METHOD'] = $method;
        $request = Craft::createObject(['class' => craft\web\Request::class, 'cookieValidationKey' => 'pointz-gql']);
        $request->setIsConsoleRequest(false);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', Craft::createObject(craft\web\Response::class));

        if ($prepare) {
            $prepare($request);
        }

        return $request;
    };
    $restore = function() use ($console, $consoleResponse) {
        Craft::$app->set('request', $console);
        Craft::$app->set('response', $consoleResponse);
        unset($_SERVER['REQUEST_METHOD']);
    };

    check('a POST without the token is refused and changes nothing', function() use ($schema, $a, $redeem, $intent, $web, $restore) {
        as_user($a);
        $before = $intent();

        try {
            $web('POST');
            $result = gql($schema, $redeem('points: 75'));
        } finally {
            $restore();
        }

        return str_contains(errors($result), 'CSRF') && ($result['data']['pointzRedeem'] ?? null) === null && $intent() == $before
            ?: Json::encode([$result, $intent()]);
    });

    check('a GET (which Craft’s /api allows) is refused too, token or not', function() use ($schema, $a, $redeem, $intent, $web, $restore) {
        as_user($a);
        $before = $intent();

        try {
            $web('GET', fn($request) => $request->getHeaders()->set('X-CSRF-Token', $request->getCsrfToken()));
            $result = gql($schema, $redeem('points: 75'));
        } finally {
            $restore();
        }

        return str_contains(errors($result), 'CSRF') && $intent() == $before ?: Json::encode([$result, $intent()]);
    });

    check('a POST with the session’s token in X-CSRF-Token goes through', function() use ($schema, $a, $redeem, $intent, $web, $restore) {
        as_user($a);

        try {
            $web('POST', fn($request) => $request->getHeaders()->set('X-CSRF-Token', $request->getCsrfToken()));
            $result = gql($schema, $redeem('points: 75'));
        } finally {
            $restore();
        }

        return ($result['data']['pointzRedeem']['success'] ?? null) === true && $intent()['points'] == 75
            ?: Json::encode([$result, $intent()]);
    });

    check('a forged token is refused', function() use ($schema, $a, $redeem, $intent, $web, $restore) {
        as_user($a);
        $before = $intent();

        try {
            $web('POST', function($request) {
                $request->getCsrfToken();
                $request->getHeaders()->set('X-CSRF-Token', Craft::$app->getSecurity()->maskToken('not-the-token'));
            });
            $result = gql($schema, $redeem('points: 10'));
        } finally {
            $restore();
        }

        return str_contains(errors($result), 'CSRF') && $intent() == $before ?: Json::encode([$result, $intent()]);
    });
}

if ($mode !== 'parent') {
    echo "\nRESULT $passed $failed\n";
    exit($failed ? 1 : 0);
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// The parent: fixtures, the children, cleanup.
// ─────────────────────────────────────────────────────────────────────────────────────────────

$tag = 'pzgql' . substr(bin2hex(random_bytes(3)), 0, 6);
$users = [];
$products = [];
$rule = null;
$cart = null;

try {
    foreach (['a', 'b'] as $key) {
        $user = new User(['username' => "$tag-$key", 'email' => "$tag-$key@pointz.example", 'active' => true]);

        if (!Craft::$app->getElements()->saveElement($user)) {
            throw new RuntimeException('Could not save fixture user: ' . Json::encode($user->getErrors()));
        }

        $users[$key] = $user;
    }

    $ledger = $plugin->getLedger();
    $soon = (new DateTime('now', new DateTimeZone('UTC')))->modify('+10 days');
    $aTransactions = [
        $ledger->credit($users['a']->id, $storeId, Rule::CURRENCY_POINTS, 1000, ['kind' => Transaction::KIND_ADJUST, 'note' => 'graphql.php A'])->id,
        $ledger->credit($users['a']->id, $storeId, Rule::CURRENCY_POINTS, 234, ['kind' => Transaction::KIND_ADJUST, 'note' => 'graphql.php A, expiring', 'dateExpires' => $soon])->id,
    ];
    $bTransactions = [
        $ledger->credit($users['b']->id, $storeId, Rule::CURRENCY_POINTS, 77, ['kind' => Transaction::KIND_ADJUST, 'note' => 'graphql.php B'])->id,
    ];

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    foreach (['shown' => true, 'hidden' => false] as $key => $enabled) {
        $product = new Product(['typeId' => $type->id, 'title' => "Pointz GraphQL $key $tag", 'enabled' => $enabled]);
        $product->setVariants([new Variant(['sku' => strtoupper("$tag-$key"), 'basePrice' => 40, 'isDefault' => true])]);

        if (!Craft::$app->getElements()->saveElement($product)) {
            throw new RuntimeException('Could not save fixture product: ' . Json::encode($product->getErrors()));
        }

        $products[$key] = $product;
    }

    $rule = new Rule([
        'storeId' => $storeId,
        'name' => "GraphQL check $tag",
        'handle' => $tag . 'Rule',
        'enabled' => true,
        'event' => Rule::EVENT_ORDER,
        'scope' => Rule::SCOPE_ORDER,
        'currency' => Rule::CURRENCY_POINTS,
        'calculation' => Rule::CALC_RATIO,
        'basis' => Rule::BASIS_ITEM_SUBTOTAL,
        'rate' => 1,
        'multiplier' => 1,
        'rounding' => Rule::ROUND_DOWN,
    ]);

    if (!$plugin->getRules()->saveRule($rule)) {
        throw new RuntimeException('Could not save fixture rule: ' . Json::encode($rule->getErrors()));
    }

    // A's cart, set up the way the signed-in customer's first redeem leaves it.
    $cart = new Order();
    $cart->storeId = $storeId;
    $cart->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $cart->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $cart->setCustomer($users['a']);
    $cart->email = $users['a']->email;
    Craft::$app->getElements()->saveElement($cart, false);
    $cart->setLineItems([Commerce::getInstance()->getLineItems()->createLineItem($cart, $products['shown']->getVariants()[0]->id, [], 3)]);
    Craft::$app->getElements()->saveElement($cart, false);
    $plugin->getRedemption()->setIntent($cart, 0, 0, $users['a']->id);

    $fixture = Json::encode([
        'a' => $users['a']->id,
        'b' => $users['b']->id,
        'aTransactions' => $aTransactions,
        'bTransactions' => $bTransactions,
        'variant' => $products['shown']->getVariants()[0]->id,
        'hiddenVariant' => $products['hidden']->getVariants()[0]->id,
        'cart' => $cart->number,
        'productTypeUid' => $type->uid,
    ]);

    foreach (['noaccess', 'read', 'redeem'] as $child) {
        $output = [];
        exec('POINTZ_GQL_FIXTURE=' . escapeshellarg($fixture) . ' ' . PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' ' . $child . ' 2>&1', $output);
        $tail = '';

        foreach ($output as $line) {
            if (preg_match('/^RESULT (\d+) (\d+)$/', $line, $m)) {
                $passed += (int)$m[1];
                $failed += (int)$m[2];
                $tail = $line;
                continue;
            }

            echo $line . "\n";
        }

        if ($tail === '') {
            $failed++;
            echo "  ✗ the $child run did not finish\n";
        }
    }
} finally {
    $elements = Craft::$app->getElements();

    if ($cart?->id) {
        $plugin->getRedemption()->clearIntent($cart->id);
        $elements->deleteElement($cart, true);
    }

    foreach ($products as $product) {
        $elements->deleteElement($product, true);
    }

    if ($rule?->id) {
        $plugin->getRules()->deleteRuleById($rule->id);
    }

    // Deleting the customer cascades the ledger, the lots and the account with it.
    foreach ($users as $user) {
        $elements->deleteElement($user, true);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
