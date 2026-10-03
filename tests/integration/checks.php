<?php
/**
 * Pointz integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every customer, rule, product and order it creates it deletes
 * again, whether the run passes or not. Nothing here touches a customer it did not create.
 *
 * The edition and the settings are changed **in memory** rather than saved: project config is
 * contended in this harness, and a console script that writes it races the queue runner.
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
use justinholtweb\pointz\adjusters\Redemption as RedemptionAdjuster;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\elements\conditions\purchasables\PointzPurchasableCondition;
use justinholtweb\pointz\errors\PointzException;
use justinholtweb\pointz\models\Lot;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Settings;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;
use justinholtweb\pointz\twig\PointzVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$ledger = $plugin->getLedger();
$accounts = $plugin->getAccounts();
$rulesService = $plugin->getRules();
$earning = $plugin->getEarning();
$redemption = $plugin->getRedemption();
$lifecycle = $plugin->getLifecycle();
$grants = $plugin->getGrants();

$suffix = substr(md5((string)microtime(true)), 0, 6);
$tag = 'PTZ' . strtoupper($suffix);

$originalEdition = $plugin->edition;
$originalSettings = clone $plugin->getSettings();

$createdUsers = [];
$createdRules = [];
$createdOrders = [];
$createdProducts = [];
$createdDiscounts = [];
$tempFiles = [];

function makeUser(string $key): User
{
    global $createdUsers, $tag;

    $user = new User();
    $user->username = strtolower($tag) . '-' . $key;
    $user->email = strtolower($tag) . '-' . $key . '@pointz.example';
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save fixture customer: ' . json_encode($user->getErrors()));
    }

    $createdUsers[] = $user;

    return $user;
}

function makeRule(string $handle, array $attributes = []): Rule
{
    global $createdRules, $storeId, $rulesService, $tag;

    $rule = new Rule(array_merge([
        'storeId' => $storeId,
        'name' => "Check $handle",
        'handle' => $tag . ucfirst($handle),
        'enabled' => true,
        'event' => Rule::EVENT_ORDER,
        'scope' => Rule::SCOPE_ORDER,
        'currency' => Rule::CURRENCY_POINTS,
        'calculation' => Rule::CALC_RATIO,
        'basis' => Rule::BASIS_ITEM_SUBTOTAL,
        'rate' => 1,
        'multiplier' => 1,
        'rounding' => Rule::ROUND_DOWN,
    ], $attributes));

    if (!$rulesService->saveRule($rule)) {
        throw new RuntimeException("Could not save rule $handle: " . json_encode($rule->getErrors()));
    }

    $createdRules[] = $rule;
    $rulesService->clearMemo();

    return $rule;
}

/**
 * Disables every rule this run has made, so each scenario starts from a known set.
 */
function onlyRules(array $keep): void
{
    global $createdRules, $rulesService;

    $keepIds = array_map(static fn(Rule $rule) => $rule->id, $keep);

    foreach ($createdRules as $rule) {
        $wanted = in_array($rule->id, $keepIds, true);

        if ($rule->enabled !== $wanted) {
            $rule->enabled = $wanted;
            $rulesService->saveRule($rule, false);
        }
    }

    $rulesService->clearMemo();
}

function makeProduct(string $sku, float $price, bool $promotable = false): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Pointz fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;
    // Commerce only lets a discount touch a promotable purchasable.
    $variant->promotable = $promotable;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

function makeCart(User $user, Variant $variant, int $qty = 1): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setCustomer($user);
    $order->email = $user->email;

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save cart: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $order->setLineItems([
        Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], $qty),
    ]);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save cart line items: ' . json_encode($order->getErrors()));
    }

    // What the signed-in customer's first visit to the redeem action records: that the cart's
    // redemptions are theirs. Without it nobody may spend on the cart (see Redemption::spenderId()).
    Plugin::getInstance()->getRedemption()->setIntent($order, 0, 0, $user->id);

    return $order;
}

function completeOrder(Order $order): Order
{
    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete order: ' . json_encode($order->getErrors()));
    }

    return Order::find()->id($order->id)->status(null)->one();
}

/**
 * A discount the way another loyalty system leaves one: a code (or several) and no owner.
 */
function makeLegacyDiscount(string $name, array $codes, float $percent, ?DateTime $dateTo = null): craft\commerce\models\Discount
{
    global $createdDiscounts, $storeId;

    $discount = new craft\commerce\models\Discount();
    $discount->storeId = $storeId;
    $discount->name = $name;
    $discount->enabled = true;
    $discount->requireCouponCode = true;
    $discount->allPurchasables = true;
    $discount->allCategories = true;
    $discount->percentDiscount = -($percent / 100);
    $discount->percentageOffSubject = craft\commerce\records\Discount::TYPE_DISCOUNTED_SALEPRICE;
    $discount->appliedTo = craft\commerce\records\Discount::APPLIED_TO_ALL_LINE_ITEMS;
    $discount->dateTo = $dateTo;

    if (!Commerce::getInstance()->getDiscounts()->saveDiscount($discount)) {
        throw new RuntimeException('Could not save fixture discount: ' . json_encode($discount->getErrors()));
    }

    $createdDiscounts[] = $discount->id;

    foreach ($codes as $code) {
        $coupon = new craft\commerce\models\Coupon();
        $coupon->discountId = $discount->id;
        $coupon->code = $code;
        $coupon->maxUses = 0;
        Commerce::getInstance()->getCoupons()->saveCoupon($coupon);
    }

    return Commerce::getInstance()->getDiscounts()->getDiscountById($discount->id);
}

function writeCsv(array $lines): string
{
    global $tempFiles;

    $path = tempnam(sys_get_temp_dir(), 'pointz-import-');
    file_put_contents($path, implode("\n", $lines) . "\n");
    $tempFiles[] = $path;

    return $path;
}

function lotRows(int $userId, int $storeId): array
{
    return (new Query())->from(Table::LOTS)->where(['userId' => $userId, 'storeId' => $storeId])->all();
}

try {
    // In memory only: project config is contended here, and `switchEdition()` would race the
    // queue runner for it.
    $plugin->edition = Plugin::EDITION_PRO;
    $settings = $plugin->getSettings();
    $settings->earningEnabled = true;
    $settings->redemptionEnabled = true;
    $settings->creditRedemptionEnabled = true;
    $settings->pointsPerUnit = 100;
    $settings->minPointsToRedeem = 0;
    $settings->redeemBlockSize = 1;
    $settings->maxRedemptionPercent = null;
    $settings->redeemableBase = Settings::BASE_ITEM_SUBTOTAL;
    $settings->holdDays = 0;
    $settings->expiryEnabled = false;
    $settings->expireAfterDays = null;
    $settings->awardOn = Settings::AWARD_ON_COMPLETE;
    $settings->onRefund = Settings::REVERSAL_PROPORTIONAL;
    $settings->returnRedeemedOnRefund = true;
    $settings->onShortfall = Settings::SHORTFALL_CLAMP;

    $product = makeProduct($tag . '-A', 25.00);
    $variantA = $product->getVariants()[0];
    $productB = makeProduct($tag . '-B', 40.00);
    $variantB = $productB->getVariants()[0];

    // ---------------------------------------------------------------------------------------
    section('Install');

    check('a disabled rule was seeded for the primary store', function() use ($rulesService, $storeId) {
        $seeded = $rulesService->getRuleByHandle('pointsOnEveryOrder', $storeId);

        if ($seeded === null) {
            return 'no seeded rule';
        }

        return $seeded->enabled === false ?: 'the seeded rule is enabled';
    });

    check('the seeded rule awards nothing while it is off', function() use ($rulesService, $storeId) {
        $active = $rulesService->getActiveRules($storeId, Rule::EVENT_ORDER);

        return !in_array('pointsOnEveryOrder', array_map(static fn(Rule $r) => $r->handle, $active), true)
            ?: 'a disabled rule is active';
    });

    // ---------------------------------------------------------------------------------------
    section('Ledger');

    $ledgerUser = makeUser('ledger');

    check('a credit writes a transaction, a lot and a balance', function() use ($ledger, $accounts, $ledgerUser, $storeId) {
        $transaction = $ledger->credit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, 500, ['note' => 'seed']);

        if ($transaction->amount != 500.0) {
            return "amount was $transaction->amount";
        }

        $lots = lotRows($ledgerUser->id, $storeId);

        if (count($lots) !== 1) {
            return 'lots: ' . count($lots);
        }

        return $accounts->getBalance($ledgerUser->id, $storeId) == 500.0
            ?: 'balance is ' . $accounts->getBalance($ledgerUser->id, $storeId);
    });

    check('the cached balance agrees with the lots', function() use ($accounts, $ledgerUser, $storeId) {
        return $accounts->getBalance($ledgerUser->id, $storeId) == $accounts->getLotBalance($ledgerUser->id, $storeId)
            ?: 'cache and lots disagree';
    });

    check('balanceAfter is stamped on the transaction', function() use ($ledger, $ledgerUser, $storeId) {
        $transaction = $ledger->credit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, 100);

        return $transaction->balanceAfter == 600.0 ?: "balanceAfter was $transaction->balanceAfter";
    });

    check('a debit consumes the soonest-expiring lot first', function() use ($ledger, $ledgerUser, $storeId, $lifecycle) {
        // A third lot that expires tomorrow must be spent before the two undated ones.
        $ledger->credit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, 50, [
            'dateExpires' => (clone $lifecycle->now())->modify('+1 day'),
        ]);

        $spend = $ledger->debit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, 60);

        $uses = (new Query())->from(Table::LOT_USES)->where(['transactionId' => $spend->id])->orderBy(['id' => SORT_ASC])->all();

        if (count($uses) !== 2) {
            return 'lot uses: ' . count($uses);
        }

        $firstLot = (new Query())->from(Table::LOTS)->where(['id' => $uses[0]['lotId']])->one();

        return $firstLot['dateExpires'] !== null && (float)$uses[0]['amount'] == 50.0
            ?: 'the dated lot was not spent first';
    });

    check('a spend leaves the balance right', function() use ($accounts, $ledgerUser, $storeId) {
        // 500 + 100 + 50 earned, 60 spent.
        return $accounts->getBalance($ledgerUser->id, $storeId) == 590.0
            ?: 'balance is ' . $accounts->getBalance($ledgerUser->id, $storeId);
    });

    check('a debit beyond the balance is refused', function() use ($ledger, $ledgerUser, $storeId) {
        try {
            $ledger->debit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, 10000);
        } catch (PointzException $e) {
            return true;
        }

        return 'the debit went through';
    });

    check('allowPartial spends what is there instead', function() use ($ledger, $accounts, $ledgerUser, $storeId) {
        $before = $accounts->getBalance($ledgerUser->id, $storeId);
        $spend = $ledger->debit($ledgerUser->id, $storeId, Rule::CURRENCY_POINTS, $before + 1000, ['allowPartial' => true]);

        return abs($spend->amount) == $before && $accounts->getBalance($ledgerUser->id, $storeId) == 0.0
            ?: "spent " . abs($spend->amount) . " of $before";
    });

    check('a restore puts value back into the lot it came from', function() use ($ledger, $accounts, $ledgerUser, $storeId) {
        $spend = $ledger->getTransactions(['userId' => $ledgerUser->id, 'kind' => Transaction::KIND_REDEEM], 1)[0];
        $lotIdsBefore = array_column(lotRows($ledgerUser->id, $storeId), 'id');

        $ledger->restore($spend, 100);

        $lotIdsAfter = array_column(lotRows($ledgerUser->id, $storeId), 'id');

        if (count($lotIdsAfter) !== count($lotIdsBefore)) {
            return 'a new lot was minted rather than the original refilled';
        }

        return $accounts->getBalance($ledgerUser->id, $storeId) == 100.0
            ?: 'balance is ' . $accounts->getBalance($ledgerUser->id, $storeId);
    });

    check('a restore cannot hand back more than was taken', function() use ($ledger, $ledgerUser) {
        $spend = $ledger->getTransactions(['userId' => $ledgerUser->id, 'kind' => Transaction::KIND_REDEEM], 1)[0];
        $outstanding = $ledger->getUnrestoredAmount($spend);
        $transaction = $ledger->restore($spend, $outstanding + 5000);

        return $transaction !== null && $transaction->amount == $outstanding
            ?: 'restored ' . ($transaction?->amount ?? 'nothing') . " of $outstanding";
    });

    check('recalculateAll agrees with the lots', function() use ($accounts, $ledgerUser, $storeId) {
        $accounts->recalculateAll();
        $accounts->clearMemo();

        return $accounts->getBalance($ledgerUser->id, $storeId) == $accounts->getLotBalance($ledgerUser->id, $storeId)
            ?: 'the rebuild disagrees with the lots';
    });

    // ---------------------------------------------------------------------------------------
    section('Earning');

    $earnUser = makeUser('earn');
    $ratioRule = makeRule('ratio', ['rate' => 2]);
    onlyRules([$ratioRule]);

    check('a ratio rule awards rate × item subtotal', function() use ($earning, $earnUser, $variantA) {
        $order = makeCart($earnUser, $variantA, 2);
        $award = $earning->evaluateOrder($order, true);

        // 2 × 25.00 = 50.00 subtotal, at 2 points per unit.
        return $award->getPoints() == 100.0 ?: 'awarded ' . $award->getPoints();
    });

    check('completing an order writes the award', function() use ($earning, $accounts, $earnUser, $storeId, $variantA) {
        $order = completeOrder(makeCart($earnUser, $variantA, 1));
        $accounts->clearMemo();

        return $accounts->getBalance($earnUser->id, $storeId) == 50.0
            ?: 'balance is ' . $accounts->getBalance($earnUser->id, $storeId);
    });

    check('awarding the same order twice is a no-op', function() use ($earning, $accounts, $earnUser, $storeId) {
        $order = Order::find()->customerId($earnUser->id)->isCompleted(true)->status(null)->one();
        $written = $earning->awardOrder($order);
        $accounts->clearMemo();

        return $written === [] && $accounts->getBalance($earnUser->id, $storeId) == 50.0
            ?: 'the order earned twice';
    });

    check('rounding down is the default', function() use ($earning, $earnUser, $variantA, $rulesService, $ratioRule) {
        $ratioRule->rate = 0.33;
        $rulesService->saveRule($ratioRule, false);
        $rulesService->clearMemo();

        // 25.00 × 0.33 = 8.25
        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 8.0 ?: 'awarded ' . $award->getPoints();
    });

    check('rounding up is honoured', function() use ($earning, $earnUser, $variantA, $rulesService, $ratioRule) {
        $ratioRule->rounding = Rule::ROUND_UP;
        $rulesService->saveRule($ratioRule, false);
        $rulesService->clearMemo();

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 9.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a maximum trims the award', function() use ($earning, $earnUser, $variantA, $rulesService, $ratioRule) {
        $ratioRule->rate = 2;
        $ratioRule->rounding = Rule::ROUND_DOWN;
        $ratioRule->maxAward = 30;
        $rulesService->saveRule($ratioRule, false);
        $rulesService->clearMemo();

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 2), true);

        return $award->getPoints() == 30.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a minimum raises it', function() use ($earning, $earnUser, $variantA, $rulesService, $ratioRule) {
        $ratioRule->maxAward = null;
        $ratioRule->rate = 0.01;
        $ratioRule->minAward = 25;
        $rulesService->saveRule($ratioRule, false);
        $rulesService->clearMemo();

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 25.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a per-customer cap counts what the rule has already given', function() use ($earning, $rulesService, $ratioRule, $variantA) {
        $capUser = makeUser('cap');
        $ratioRule->minAward = null;
        $ratioRule->rate = 2;
        $ratioRule->maxPerUser = 60;
        $ratioRule->maxPerUserPeriod = Rule::PERIOD_EVER;
        $rulesService->saveRule($ratioRule, false);
        $rulesService->clearMemo();

        // 50.00 subtotal at 2/unit = 100, capped to 60.
        completeOrder(makeCart($capUser, $variantA, 2));
        Plugin::getInstance()->getAccounts()->clearMemo();
        $first = Plugin::getInstance()->getAccounts()->getBalance($capUser->id, $ratioRule->storeId);

        // The cap is now spent, so a second order earns nothing.
        completeOrder(makeCart($capUser, $variantA, 2));
        Plugin::getInstance()->getAccounts()->clearMemo();
        $second = Plugin::getInstance()->getAccounts()->getBalance($capUser->id, $ratioRule->storeId);

        return $first == 60.0 && $second == 60.0 ?: "first $first, second $second";
    });

    check('a fixed award ignores the basis', function() use ($earning, $earnUser, $variantA) {
        $fixed = makeRule('fixed', [
            'calculation' => Rule::CALC_FIXED,
            'rate' => 15,
        ]);
        onlyRules([$fixed]);

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 4), true);

        return $award->getPoints() == 15.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a campaign window that has closed awards nothing', function() use ($earning, $earnUser, $variantA, $lifecycle) {
        $expired = makeRule('expiredCampaign', [
            'rate' => 5,
            'dateFrom' => (clone $lifecycle->now())->modify('-10 days'),
            'dateTo' => (clone $lifecycle->now())->modify('-1 day'),
        ]);
        onlyRules([$expired]);

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 0.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a line-item rule only counts matching products', function() use ($earning, $earnUser, $variantA, $variantB, $storeId, $rulesService) {
        $lineRule = makeRule('lineItem', [
            'scope' => Rule::SCOPE_LINE_ITEM,
            'basis' => Rule::BASIS_LINE_ITEM_SUBTOTAL,
            'rate' => 3,
        ]);

        $lineRule->setPurchasableCondition(Craft::$app->getConditions()->createCondition([
            'class' => PointzPurchasableCondition::class,
            'conditionRules' => [
                [
                    'class' => \craft\commerce\elements\conditions\purchasables\SkuConditionRule::class,
                    'operator' => '=',
                    'value' => $variantA->sku,
                ],
            ],
        ]));

        $rulesService->saveRule($lineRule, false);
        $rulesService->clearMemo();
        onlyRules([$lineRule]);

        $order = makeCart($earnUser, $variantA, 1);
        $order->addLineItem(Commerce::getInstance()->getLineItems()->createLineItem($order, $variantB->id, [], 1));
        Craft::$app->getElements()->saveElement($order, false);

        $award = $earning->evaluateOrder($order, true);

        // Only the 25.00 line matches, at 3/unit.
        return $award->getPoints() == 75.0 ?: 'awarded ' . $award->getPoints();
    });

    check('“stop after this one” ends the run', function() use ($earning, $earnUser, $variantA) {
        $first = makeRule('stopper', ['rate' => 1, 'stopProcessing' => true]);
        $second = makeRule('never', ['rate' => 10]);
        onlyRules([$first, $second]);

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 25.0 ?: 'awarded ' . $award->getPoints();
    });

    check('two rules both contribute when neither stops', function() use ($earning, $earnUser, $variantA) {
        $first = makeRule('both1', ['rate' => 1]);
        $second = makeRule('both2', ['rate' => 2]);
        onlyRules([$first, $second]);

        $award = $earning->evaluateOrder(makeCart($earnUser, $variantA, 1), true);

        return $award->getPoints() == 75.0 ?: 'awarded ' . $award->getPoints();
    });

    check('a first-order rule skips a returning customer', function() use ($earning, $variantA) {
        $returning = makeUser('returning');
        $welcome = makeRule('firstOrder', ['rate' => 1, 'firstOrderOnly' => true]);
        onlyRules([$welcome]);

        completeOrder(makeCart($returning, $variantA, 1));
        Plugin::getInstance()->getAccounts()->clearMemo();
        $first = Plugin::getInstance()->getAccounts()->getBalance($returning->id, $welcome->storeId);

        completeOrder(makeCart($returning, $variantA, 1));
        Plugin::getInstance()->getAccounts()->clearMemo();
        $second = Plugin::getInstance()->getAccounts()->getBalance($returning->id, $welcome->storeId);

        return $first == 25.0 && $second == 25.0 ?: "first $first, second $second";
    });

    check('a signup rule awards once and only once', function() use ($earning, $storeId, $accounts) {
        $signupRule = makeRule('signup', [
            'event' => Rule::EVENT_SIGNUP,
            'calculation' => Rule::CALC_FIXED,
            'rate' => 250,
        ]);
        onlyRules([$signupRule]);

        $newcomer = makeUser('newcomer');
        $accounts->clearMemo();
        $first = $accounts->getBalance($newcomer->id, $storeId);

        // The user save has already fired the handler; asking again must change nothing.
        $earning->awardSignup($newcomer, $storeId);
        $accounts->clearMemo();
        $second = $accounts->getBalance($newcomer->id, $storeId);

        return $first == 250.0 && $second == 250.0 ?: "first $first, second $second";
    });

    // ---------------------------------------------------------------------------------------
    section('Redeeming');

    onlyRules([]);
    $redeemUser = makeUser('redeem');
    $ledger->credit($redeemUser->id, $storeId, Rule::CURRENCY_POINTS, 5000);
    $accounts->clearMemo();

    check('a quote converts points to money at the configured rate', function() use ($redemption, $redeemUser, $variantA) {
        $cart = makeCart($redeemUser, $variantA, 1);
        $quote = $redemption->quote($cart, 1000);

        // 1000 points at 100 per unit = 10.00.
        return $quote->points == 1000.0 && $quote->pointsValue == 10.0
            ?: "points {$quote->points}, value {$quote->pointsValue}";
    });

    check('a quote is clamped to the balance', function() use ($redemption, $redeemUser, $variantA) {
        $cart = makeCart($redeemUser, $variantA, 40);
        $quote = $redemption->quote($cart, 99999);

        return $quote->points == 5000.0 && $quote->notices !== []
            ?: "points {$quote->points}, notices " . count($quote->notices);
    });

    check('a quote is clamped to the order', function() use ($redemption, $redeemUser, $variantA) {
        // A 25.00 order can only take 2,500 points.
        $cart = makeCart($redeemUser, $variantA, 1);
        $quote = $redemption->quote($cart, 5000);

        return $quote->points == 2500.0 ?: 'points ' . $quote->points;
    });

    check('a block size rounds a request down', function() use ($redemption, $redeemUser, $variantA, $settings) {
        $settings->redeemBlockSize = 500;
        $cart = makeCart($redeemUser, $variantA, 1);
        $quote = $redemption->quote($cart, 1400);
        $settings->redeemBlockSize = 1;

        return $quote->points == 1000.0 ?: 'points ' . $quote->points;
    });

    check('a minimum below which nothing may be redeemed is enforced', function() use ($redemption, $redeemUser, $variantA, $settings) {
        $settings->minPointsToRedeem = 1000;
        $cart = makeCart($redeemUser, $variantA, 1);
        $quote = $redemption->quote($cart, 500);
        $settings->minPointsToRedeem = 0;

        return $quote->points == 0.0 && $quote->notices !== [] ?: 'points ' . $quote->points;
    });

    check('a percentage cap limits the discount', function() use ($redemption, $redeemUser, $variantA, $settings) {
        $settings->maxRedemptionPercent = 50;
        // 25.00 order, half of it redeemable = 12.50 = 1,250 points.
        $cart = makeCart($redeemUser, $variantA, 1);
        $quote = $redemption->quote($cart, 5000);
        $settings->maxRedemptionPercent = null;

        return $quote->points == 1250.0 ?: 'points ' . $quote->points;
    });

    check('applying an intent puts an adjustment on the cart', function() use ($redemption, $redeemUser, $variantA) {
        $cart = makeCart($redeemUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        Craft::$app->getElements()->saveElement($cart, false);

        $adjustments = array_values(array_filter(
            $cart->getAdjustments(),
            static fn($a) => $a->type === RedemptionAdjuster::TYPE_POINTS
        ));

        if (count($adjustments) !== 1) {
            return 'adjustments: ' . count($adjustments);
        }

        return $adjustments[0]->amount == -10.0 && ($adjustments[0]->sourceSnapshot['points'] ?? null) == 1000
            ?: 'amount ' . $adjustments[0]->amount;
    });

    check('the cart total comes down by the discount', function() use ($redemption, $redeemUser, $variantA) {
        $cart = makeCart($redeemUser, $variantA, 2);
        $before = $cart->getTotal();
        $redemption->setIntent($cart, 1000);

        return round($cart->getTotal(), 2) == round($before - 10.0, 2)
            ?: 'total went from ' . $before . ' to ' . $cart->getTotal();
    });

    check('recalculating twice does not compound the discount', function() use ($redemption, $redeemUser, $variantA) {
        $cart = makeCart($redeemUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        $first = $cart->getTotal();
        $cart->recalculate();
        $cart->recalculate();

        return round($cart->getTotal(), 2) == round($first, 2)
            ?: "total drifted from $first to " . $cart->getTotal();
    });

    check('nothing is spent until the order completes', function() use ($redemption, $accounts, $redeemUser, $storeId, $variantA) {
        $before = $accounts->getBalance($redeemUser->id, $storeId);
        $cart = makeCart($redeemUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        $accounts->clearMemo();

        return $accounts->getBalance($redeemUser->id, $storeId) == $before
            ?: 'the balance moved while the cart was still a cart';
    });

    check('completing the order spends exactly the snapshot', function() use ($redemption, $accounts, $redeemUser, $storeId, $variantA) {
        $accounts->clearMemo();
        $before = $accounts->getBalance($redeemUser->id, $storeId);

        $cart = makeCart($redeemUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        Craft::$app->getElements()->saveElement($cart, false);
        completeOrder($cart);

        $accounts->clearMemo();

        return $accounts->getBalance($redeemUser->id, $storeId) == $before - 1000
            ?: 'balance went from ' . $before . ' to ' . $accounts->getBalance($redeemUser->id, $storeId);
    });

    check('the intent is cleared once it has been spent', function() use ($redemption, $redeemUser) {
        $order = Order::find()->customerId($redeemUser->id)->isCompleted(true)->status(null)->orderBy(['id' => SORT_DESC])->one();
        $intent = $redemption->getIntent($order->id);

        return $intent['points'] == 0.0 ?: 'the intent survived completion';
    });

    check('committing the same order twice spends nothing more', function() use ($redemption, $accounts, $redeemUser, $storeId) {
        $order = Order::find()->customerId($redeemUser->id)->isCompleted(true)->status(null)->orderBy(['id' => SORT_DESC])->one();
        $accounts->clearMemo();
        $before = $accounts->getBalance($redeemUser->id, $storeId);

        $redemption->commitOrder($order);
        $accounts->clearMemo();

        return $accounts->getBalance($redeemUser->id, $storeId) == $before ?: 'the order was charged twice';
    });

    check('a shortfall clamps rather than failing the checkout', function() use ($redemption, $accounts, $ledger, $storeId, $variantA, $settings) {
        $shortUser = makeUser('short');
        $ledger->credit($shortUser->id, $storeId, Rule::CURRENCY_POINTS, 1000);
        $accounts->clearMemo();

        $cart = makeCart($shortUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        Craft::$app->getElements()->saveElement($cart, false);

        // The balance disappears between the quote and the completion.
        $ledger->debit($shortUser->id, $storeId, Rule::CURRENCY_POINTS, 600);
        $accounts->clearMemo();

        completeOrder($cart);
        $accounts->clearMemo();

        return $accounts->getBalance($shortUser->id, $storeId) == 0.0
            ?: 'balance is ' . $accounts->getBalance($shortUser->id, $storeId);
    });

    check('earning ignores value paid for with points', function() use ($earning, $redemption, $ledger, $accounts, $storeId, $variantA) {
        $mixedUser = makeUser('mixed');
        $ledger->credit($mixedUser->id, $storeId, Rule::CURRENCY_POINTS, 1000);
        $accounts->clearMemo();

        $rule = makeRule('mixedEarn', ['rate' => 1]);
        onlyRules([$rule]);

        // 50.00 of goods, 10.00 of it paid in points, so 40.00 earns.
        $cart = makeCart($mixedUser, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        $award = $earning->evaluateOrder($cart, true);

        return $award->getPoints() == 40.0 ?: 'awarded ' . $award->getPoints();
    });

    // ---------------------------------------------------------------------------------------
    section('Holding and expiry');

    check('a hold makes new value pending rather than spendable', function() use ($settings, $accounts, $storeId, $variantA) {
        $settings->holdDays = 7;
        $rule = makeRule('held', ['rate' => 1]);
        onlyRules([$rule]);

        $heldUser = makeUser('held');
        completeOrder(makeCart($heldUser, $variantA, 1));
        $accounts->clearMemo();
        $account = $accounts->getAccount($heldUser->id, $storeId);
        $settings->holdDays = 0;

        return $account->pointsBalance == 0.0 && $account->pendingPoints == 25.0
            ?: "balance {$account->pointsBalance}, pending {$account->pendingPoints}";
    });

    check('a due hold is released by the sweep', function() use ($lifecycle, $accounts, $storeId, $ledger) {
        $releaseUser = makeUser('release');
        $ledger->credit($releaseUser->id, $storeId, Rule::CURRENCY_POINTS, 300, [
            'pending' => true,
            'dateAvailable' => (clone $lifecycle->now())->modify('-1 hour'),
        ]);

        $released = $lifecycle->promoteDueLots();
        $accounts->clearMemo();

        return $released >= 1 && $accounts->getBalance($releaseUser->id, $storeId) == 300.0
            ?: 'balance is ' . $accounts->getBalance($releaseUser->id, $storeId);
    });

    check('a released transaction stops being pending', function() use ($ledger, $storeId) {
        $rows = (new Query())->from(Table::TRANSACTIONS)
            ->where(['status' => Transaction::STATUS_PENDING])
            ->andWhere(['<', 'id', 0])
            ->count();

        return $rows == 0 ?: 'unexpected pending rows';
    });

    check('expiry retires a lot and records what was left', function() use ($settings, $ledger, $lifecycle, $accounts, $storeId) {
        $settings->expiryEnabled = true;
        $expireUser = makeUser('expire');

        $ledger->credit($expireUser->id, $storeId, Rule::CURRENCY_POINTS, 400, [
            'dateExpires' => (clone $lifecycle->now())->modify('-1 day'),
        ]);
        $ledger->debit($expireUser->id, $storeId, Rule::CURRENCY_POINTS, 150);

        $lifecycle->expireDueLots();
        $accounts->clearMemo();

        $expiry = $ledger->getTransactions(['userId' => $expireUser->id, 'kind' => Transaction::KIND_EXPIRE], 1);
        $settings->expiryEnabled = false;

        if (!$expiry) {
            return 'no expiry transaction';
        }

        // 400 earned, 150 spent, so 250 expired — not 400.
        return abs($expiry[0]->amount) == 250.0 && $accounts->getBalance($expireUser->id, $storeId) == 0.0
            ?: 'expired ' . abs($expiry[0]->amount);
    });

    check('expiry does nothing while it is switched off', function() use ($settings, $ledger, $lifecycle, $storeId, $accounts) {
        $settings->expiryEnabled = false;
        $safeUser = makeUser('safe');

        $ledger->credit($safeUser->id, $storeId, Rule::CURRENCY_POINTS, 100, [
            'dateExpires' => (clone $lifecycle->now())->modify('-1 day'),
        ]);

        $lifecycle->expireDueLots();
        $accounts->clearMemo();

        return $accounts->getBalance($safeUser->id, $storeId) == 100.0
            ?: 'balance is ' . $accounts->getBalance($safeUser->id, $storeId);
    });

    check('the expiry date comes from the rule when it has one', function() use ($settings, $lifecycle) {
        $settings->expiryEnabled = true;
        $settings->expireAfterDays = 365;

        $ruleDate = $lifecycle->expiryDateFor(30);
        $globalDate = $lifecycle->expiryDateFor(null);

        $settings->expiryEnabled = false;
        $settings->expireAfterDays = null;

        return $ruleDate < $globalDate ?: 'the rule override was ignored';
    });

    // ---------------------------------------------------------------------------------------
    section('Refunds');

    check('a full refund takes back what the order earned', function() use ($settings, $accounts, $lifecycle, $storeId, $variantA) {
        $settings->onRefund = Settings::REVERSAL_FULL;
        $rule = makeRule('refundFull', ['rate' => 1]);
        onlyRules([$rule]);

        $refundUser = makeUser('refundFull');
        $order = completeOrder(makeCart($refundUser, $variantA, 2));
        $accounts->clearMemo();
        $earned = $accounts->getBalance($refundUser->id, $storeId);

        $lifecycle->reverseForRefund($order, $order->getTotalPrice());
        $accounts->clearMemo();

        $settings->onRefund = Settings::REVERSAL_PROPORTIONAL;

        return $earned == 50.0 && $accounts->getBalance($refundUser->id, $storeId) == 0.0
            ?: "earned $earned, left " . $accounts->getBalance($refundUser->id, $storeId);
    });

    check('a partial refund takes back its share', function() use ($accounts, $lifecycle, $storeId, $variantA) {
        $rule = makeRule('refundHalf', ['rate' => 1]);
        onlyRules([$rule]);

        $refundUser = makeUser('refundHalf');
        $order = completeOrder(makeCart($refundUser, $variantA, 2));
        $accounts->clearMemo();

        // Half of a 50.00 order.
        $lifecycle->reverseForRefund($order, $order->getTotalPrice() / 2);
        $accounts->clearMemo();

        return $accounts->getBalance($refundUser->id, $storeId) == 25.0
            ?: 'balance is ' . $accounts->getBalance($refundUser->id, $storeId);
    });

    check('two partial refunds are cumulative, not repeated', function() use ($accounts, $lifecycle, $storeId, $variantA) {
        $rule = makeRule('refundTwice', ['rate' => 1]);
        onlyRules([$rule]);

        $refundUser = makeUser('refundTwice');
        $order = completeOrder(makeCart($refundUser, $variantA, 2));
        $accounts->clearMemo();

        $total = $order->getTotalPrice();
        $lifecycle->reverseForRefund($order, $total * 0.25);
        $lifecycle->reverseForRefund($order, $total * 0.5);
        $accounts->clearMemo();

        // 50 earned, half of it gone in total.
        return $accounts->getBalance($refundUser->id, $storeId) == 25.0
            ?: 'balance is ' . $accounts->getBalance($refundUser->id, $storeId);
    });

    check('a refund hands back the points the order spent', function() use ($settings, $redemption, $ledger, $accounts, $lifecycle, $storeId, $variantA) {
        onlyRules([]);
        $spender = makeUser('spender');
        $ledger->credit($spender->id, $storeId, Rule::CURRENCY_POINTS, 2000);
        $accounts->clearMemo();

        $cart = makeCart($spender, $variantA, 2);
        $redemption->setIntent($cart, 1000);
        Craft::$app->getElements()->saveElement($cart, false);
        $order = completeOrder($cart);
        $accounts->clearMemo();

        $afterSpend = $accounts->getBalance($spender->id, $storeId);

        $lifecycle->reverseForRefund($order, $order->getTotalPrice());
        $accounts->clearMemo();

        return $afterSpend == 1000.0 && $accounts->getBalance($spender->id, $storeId) == 2000.0
            ?: "after spend $afterSpend, after refund " . $accounts->getBalance($spender->id, $storeId);
    });

    check('“leave earned value alone” does exactly that', function() use ($settings, $accounts, $lifecycle, $storeId, $variantA) {
        $settings->onRefund = Settings::REVERSAL_NONE;
        $settings->returnRedeemedOnRefund = false;
        $rule = makeRule('refundNone', ['rate' => 1]);
        onlyRules([$rule]);

        $refundUser = makeUser('refundNone');
        $order = completeOrder(makeCart($refundUser, $variantA, 2));
        $accounts->clearMemo();

        $lifecycle->reverseForRefund($order, $order->getTotalPrice());
        $accounts->clearMemo();

        $settings->onRefund = Settings::REVERSAL_PROPORTIONAL;
        $settings->returnRedeemedOnRefund = true;

        return $accounts->getBalance($refundUser->id, $storeId) == 50.0
            ?: 'balance is ' . $accounts->getBalance($refundUser->id, $storeId);
    });

    // ---------------------------------------------------------------------------------------
    section('Store credit');

    check('credit is its own balance', function() use ($grants, $accounts, $storeId) {
        $creditUser = makeUser('credit');
        $grants->grant($creditUser->id, $storeId, Rule::CURRENCY_CREDIT, 20.00, 'goodwill');
        $accounts->clearMemo();
        $account = $accounts->getAccount($creditUser->id, $storeId);

        return $account->creditBalance == 20.0 && $account->pointsBalance == 0.0
            ?: "credit {$account->creditBalance}, points {$account->pointsBalance}";
    });

    check('credit is applied to a cart alongside points', function() use ($grants, $ledger, $redemption, $accounts, $storeId, $variantA) {
        $bothUser = makeUser('both');
        $ledger->credit($bothUser->id, $storeId, Rule::CURRENCY_POINTS, 1000);
        $grants->grant($bothUser->id, $storeId, Rule::CURRENCY_CREDIT, 5.00);
        $accounts->clearMemo();

        $cart = makeCart($bothUser, $variantA, 2);
        $quote = $redemption->quote($cart, 1000, 5.00);

        return $quote->pointsValue == 10.0 && $quote->credit == 5.0
            ?: "points {$quote->pointsValue}, credit {$quote->credit}";
    });

    check('credit is capped by what is left on the order, not by the percentage', function() use ($grants, $redemption, $accounts, $storeId, $variantA, $settings) {
        $capUser = makeUser('creditCap');
        $grants->grant($capUser->id, $storeId, Rule::CURRENCY_CREDIT, 100.00);
        $accounts->clearMemo();

        $settings->maxRedemptionPercent = 10;
        $cart = makeCart($capUser, $variantA, 1);
        $quote = $redemption->quote($cart, 0, 100.00);
        $settings->maxRedemptionPercent = null;

        // The 10% cap is for points; credit may still cover the whole 25.00 order.
        return $quote->credit == 25.0 ?: 'credit ' . $quote->credit;
    });

    check('a refund can be paid as store credit', function() use ($grants, $accounts, $storeId, $variantA) {
        onlyRules([]);
        $creditUser = makeUser('refundCredit');
        $order = completeOrder(makeCart($creditUser, $variantA, 2));

        $grants->refundToCredit($order, 12.50, 'returned one item');
        $accounts->clearMemo();

        return $accounts->getAccount($creditUser->id, $storeId)->creditBalance == 12.5
            ?: 'credit is ' . $accounts->getAccount($creditUser->id, $storeId)->creditBalance;
    });

    check('Lite refuses to move store credit', function() use ($grants, $storeId, $plugin) {
        $plugin->edition = Plugin::EDITION_LITE;
        $liteUser = makeUser('lite');

        try {
            $grants->grant($liteUser->id, $storeId, Rule::CURRENCY_CREDIT, 10.00);
            $plugin->edition = Plugin::EDITION_PRO;

            return 'Lite issued store credit';
        } catch (PointzException $e) {
            $plugin->edition = Plugin::EDITION_PRO;

            return true;
        }
    });

    check('Lite runs one earning rule per store', function() use ($plugin, $rulesService, $storeId) {
        $first = makeRule('liteOne', ['rate' => 1]);
        $second = makeRule('liteTwo', ['rate' => 5]);
        onlyRules([$first, $second]);

        $plugin->edition = Plugin::EDITION_LITE;
        $active = $rulesService->getActiveRules($storeId, Rule::EVENT_ORDER);
        $plugin->edition = Plugin::EDITION_PRO;

        return count($active) === 1 ?: 'active rules: ' . count($active);
    });

    // ---------------------------------------------------------------------------------------
    section('Grants and batches');

    check('a batch grant can be reversed as a unit', function() use ($grants, $accounts, $storeId) {
        $one = makeUser('batch1');
        $two = makeUser('batch2');

        $result = $grants->grantMany([$one->id, $two->id], $storeId, Rule::CURRENCY_POINTS, 500, 'launch bonus');
        $accounts->clearMemo();

        if ($result['granted'] !== 2) {
            return 'granted ' . $result['granted'];
        }

        $grants->reverseBatch($result['batchId']);
        $accounts->clearMemo();

        return $accounts->getBalance($one->id, $storeId) == 0.0 && $accounts->getBalance($two->id, $storeId) == 0.0
            ?: 'the reversal left value behind';
    });

    check('a reversal cannot take back what was already spent', function() use ($grants, $ledger, $accounts, $storeId) {
        $spent = makeUser('spentBatch');
        $result = $grants->grantMany([$spent->id], $storeId, Rule::CURRENCY_POINTS, 500);
        $accounts->clearMemo();

        $ledger->debit($spent->id, $storeId, Rule::CURRENCY_POINTS, 200);
        $accounts->clearMemo();

        $outcome = $grants->reverseBatch($result['batchId']);
        $accounts->clearMemo();

        // 300 was left to take; the other 200 is reported short rather than pushing it negative.
        return $accounts->getBalance($spent->id, $storeId) == 0.0 && $outcome['short'] === 1
            ?: 'balance ' . $accounts->getBalance($spent->id, $storeId) . ', short ' . $outcome['short'];
    });

    check('a deduction takes from the soonest-expiring lot', function() use ($grants, $ledger, $accounts, $lifecycle, $storeId) {
        $deductUser = makeUser('deduct');
        $ledger->credit($deductUser->id, $storeId, Rule::CURRENCY_POINTS, 100);
        $dated = $ledger->credit($deductUser->id, $storeId, Rule::CURRENCY_POINTS, 100, [
            'dateExpires' => (clone $lifecycle->now())->modify('+2 days'),
        ]);
        $accounts->clearMemo();

        $spend = $grants->deduct($deductUser->id, $storeId, Rule::CURRENCY_POINTS, 100, 'correction');

        $use = (new Query())->from(Table::LOT_USES)->where(['transactionId' => $spend->id])->one();
        $lot = (new Query())->from(Table::LOTS)->where(['id' => $use['lotId']])->one();

        return $lot['transactionId'] == $dated->id ?: 'the undated lot was taken first';
    });

    // ---------------------------------------------------------------------------------------
    section('Backfill');

    check('a plan reports what would be awarded without writing it', function() use ($plugin, $accounts, $storeId, $variantA, $lifecycle) {
        onlyRules([]);
        $backfillUser = makeUser('backfill');
        $order = completeOrder(makeCart($backfillUser, $variantA, 2));

        // The rule is switched on only now, so the order completed without earning.
        $rule = makeRule('backfillRule', ['rate' => 1]);
        onlyRules([$rule]);

        $from = (clone $lifecycle->now())->modify('-2 minutes');
        $plan = $plugin->getBackfill()->plan(['from' => $from, 'storeId' => $storeId]);

        $accounts->clearMemo();

        return $plan['orders'] >= 1 && $accounts->getBalance($backfillUser->id, $storeId) == 0.0
            ?: 'plan wrote something, or found nothing';
    });

    check('a run awards them and can be reverted', function() use ($plugin, $accounts, $storeId, $lifecycle) {
        $from = (clone $lifecycle->now())->modify('-2 minutes');
        $result = $plugin->getBackfill()->run(['from' => $from, 'storeId' => $storeId]);
        $accounts->clearMemo();

        if ($result['orders'] < 1) {
            return 'nothing was awarded';
        }

        $awarded = $result['points'];

        $plugin->getBackfill()->revert($result['batchId']);
        $accounts->clearMemo();

        $left = (float)(new Query())
            ->from(Table::TRANSACTIONS)
            ->where(['batchId' => $result['batchId']])
            ->sum('[[amount]]');

        return $awarded > 0 && abs($left) < 0.00001 ?: "awarded $awarded, ledger nets to $left";
    });

    // ---------------------------------------------------------------------------------------
    section('Twig API');

    $variable = new PointzVariable();

    check('the variable reads a balance', function() use ($variable, $ledger, $accounts, $storeId) {
        $twigUser = makeUser('twig');
        $ledger->credit($twigUser->id, $storeId, Rule::CURRENCY_POINTS, 750);
        $accounts->clearMemo();

        return $variable->balance($twigUser, $storeId) == 750.0
            ?: 'read ' . $variable->balance($twigUser, $storeId);
    });

    check('the variable converts between points and money', function() use ($variable) {
        return $variable->value(1000) == 10.0 && $variable->points(10) == 1000.0
            ?: 'value ' . $variable->value(1000) . ', points ' . $variable->points(10);
    });

    check('the variable names a quantity in the store’s own words', function() use ($variable, $settings) {
        $settings->pointsLabel = 'star';
        $settings->pointsLabelPlural = 'stars';
        $one = $variable->label(1);
        $many = $variable->formatted(240);
        $settings->pointsLabel = 'point';
        $settings->pointsLabelPlural = 'points';

        return $one === 'star' && $many === '240 stars' ?: "$one / $many";
    });

    check('a product preview uses the same arithmetic as a real order', function() use ($variable, $earning, $variantA) {
        $rule = makeRule('preview', ['rate' => 2]);
        onlyRules([$rule]);

        $preview = $variable->earnFor($variantA, 2);

        return $preview->getPoints() == 100.0 ?: 'previewed ' . $preview->getPoints();
    });

    check('a preview says so when a rule cannot be answered for one product', function() use ($variable, $variantA) {
        $rule = makeRule('orderWide', ['rate' => 1, 'basis' => Rule::BASIS_TOTAL]);
        onlyRules([$rule]);

        $preview = $variable->earnFor($variantA, 1);

        return $preview->notices !== [] ?: 'no notice about the order-wide rule';
    });

    check('a preview cannot write anything', function() use ($variable, $accounts, $variantA, $storeId) {
        $previewUser = makeUser('previewOnly');
        $variable->earnFor($variantA, 3);
        $accounts->clearMemo();

        return $accounts->getAccount($previewUser->id, $storeId) === null
            ?: 'a preview created an account';
    });

    // ---------------------------------------------------------------------------------------
    section('Rewards');

    $rewards = $plugin->getRewards();
    $couponsService = $plugin->getCoupons();

    check('a custom-event rule pays once per customer, and a reference makes each instance its own', function() use ($rewards, $accounts, $storeId) {
        $rule = makeRule('newsletter', ['event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'newsletter', 'rate' => 50]);
        onlyRules([$rule]);
        $user = makeUser('newsletter');

        $first = $rewards->awardEvent($user, 'newsletter');
        $again = $rewards->awardEvent($user->email, 'newsletter');
        $other = $rewards->awardEvent($user, 'somethingElse');
        $refA = $rewards->awardEvent($user, 'newsletter', reference: 'issue:1');
        $refA2 = $rewards->awardEvent($user, 'newsletter', reference: 'issue:1');
        $accounts->clearMemo();
        $balance = $accounts->getAccount($user->id, $storeId)?->pointsBalance;

        if (count($first) !== 1 || $again !== [] || $other !== [] || count($refA) !== 1 || $refA2 !== []) {
            return sprintf('paid %d, %d, %d, %d, %d', count($first), count($again), count($other), count($refA), count($refA2));
        }

        return $balance == 100.0 ?: "balance $balance";
    });

    check('a custom-event handle ending in * matches several events', function() use ($rewards) {
        $rule = makeRule('formieAll', ['event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'formie:*', 'rate' => 5]);
        onlyRules([$rule]);
        $user = makeUser('wildcard');

        return count($rewards->awardEvent($user, 'formie:contact')) === 1
            && count($rewards->awardEvent($user, 'dispatch:club')) === 0
            ?: 'the wildcard did not match as expected';
    });

    check('a Formie submission fires its form’s custom event', function() use ($accounts, $storeId) {
        if (!class_exists(\verbb\formie\services\Submissions::class)) {
            return true;
        }

        $rule = makeRule('formieNews', ['event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'formie:pointzNews', 'rate' => 30]);
        onlyRules([$rule]);
        $user = makeUser('formie');

        $submission = new \verbb\formie\elements\Submission();
        $submission->userId = $user->id;
        $form = new \verbb\formie\elements\Form();
        $form->handle = 'pointzNews';

        yii\base\Event::trigger(\verbb\formie\services\Submissions::class, 'afterSubmission', new \verbb\formie\events\SubmissionEvent([
            'submission' => $submission,
            'form' => $form,
            'success' => true,
        ]));

        $spam = new \verbb\formie\elements\Submission();
        $spam->userId = $user->id;
        $spam->isSpam = true;
        yii\base\Event::trigger(\verbb\formie\services\Submissions::class, 'afterSubmission', new \verbb\formie\events\SubmissionEvent([
            'submission' => $spam,
            'form' => $form,
            'success' => true,
        ]));

        $accounts->clearMemo();
        $balance = $accounts->getAccount($user->id, $storeId)?->pointsBalance;

        return $balance == 30.0 ?: "balance $balance";
    });

    check('a Dispatch signup pays from a site request, and an import does not', function() use ($accounts, $storeId, $tag) {
        if (!class_exists(\justinholtweb\dispatch\Plugin::class) || !Craft::$app->getPlugins()->isPluginEnabled('dispatch')) {
            return true;
        }

        $elements = Craft::$app->getElements();
        $user = makeUser('dispatch');
        $made = [];
        $consoleRequest = Craft::$app->getRequest();

        try {
            $list = new \justinholtweb\dispatch\elements\MailingList();
            $list->name = $list->title = 'Pointz check';
            $list->handle = strtolower($tag) . 'List';
            $elements->saveElement($list) || throw new RuntimeException('list: ' . json_encode($list->getErrors()));
            $made[] = $list;

            $subscriber = new \justinholtweb\dispatch\elements\Subscriber();
            $subscriber->email = $user->email;
            $elements->saveElement($subscriber) || throw new RuntimeException('subscriber: ' . json_encode($subscriber->getErrors()));
            $made[] = $subscriber;

            $rule = makeRule('dispatchList', ['event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'dispatch:' . $list->handle, 'rate' => 25]);
            onlyRules([$rule]);
            $subscribers = \justinholtweb\dispatch\Plugin::getInstance()->subscribers;

            // From the console, as an import would: nothing.
            $subscribers->subscribe($subscriber->id, $list->id);
            $accounts->clearMemo();
            $afterImport = $accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0;

            // As a visitor's request to Dispatch's subscribe action.
            \justinholtweb\dispatch\records\SubscriptionRecord::deleteAll(['subscriberId' => $subscriber->id]);
            $_SERVER['REQUEST_URI'] = '/actions/dispatch/api/subscribe';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['HTTP_HOST'] = 'localhost';
            $web = Craft::createObject(['class' => craft\web\Request::class, 'cookieValidationKey' => 'pointz-checks']);
            $web->setIsConsoleRequest(false);
            Craft::$app->set('request', $web);
            $subscribers->subscribe($subscriber->id, $list->id);
            Craft::$app->set('request', $consoleRequest);

            $accounts->clearMemo();
            $afterVisit = $accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0;

            return $afterImport == 0.0 && $afterVisit == 25.0 ?: "import $afterImport, visit $afterVisit";
        } finally {
            Craft::$app->set('request', $consoleRequest);

            foreach (array_reverse($made) as $element) {
                $elements->deleteElement($element, true);
            }
        }
    });

    check('Lite refuses to save a Pro trigger or a coupon reward', function() use ($plugin, $storeId, $tag) {
        $plugin->edition = Plugin::EDITION_LITE;

        try {
            $custom = new Rule(['storeId' => $storeId, 'name' => 'x', 'handle' => $tag . 'liteCustom', 'event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'x']);
            $coupon = new Rule(['storeId' => $storeId, 'name' => 'y', 'handle' => $tag . 'liteCoupon', 'event' => Rule::EVENT_SIGNUP, 'currency' => Rule::CURRENCY_COUPON, 'couponAmount' => 10]);

            return !$custom->validate() && $custom->hasErrors('event') && !$coupon->validate() && $coupon->hasErrors('event')
                ?: 'Lite accepted a Pro rule';
        } finally {
            $plugin->edition = Plugin::EDITION_PRO;
        }
    });

    check('an order rule cannot award a coupon, and a threshold cannot award points', function() use ($storeId, $tag) {
        $order = new Rule(['storeId' => $storeId, 'name' => 'o', 'handle' => $tag . 'orderCoupon', 'currency' => Rule::CURRENCY_COUPON, 'couponAmount' => 10]);
        $threshold = new Rule(['storeId' => $storeId, 'name' => 't', 'handle' => $tag . 'thresholdPoints', 'event' => Rule::EVENT_THRESHOLD, 'thresholdPoints' => 10, 'currency' => Rule::CURRENCY_POINTS]);

        return !$order->validate() && $order->hasErrors('currency') && !$threshold->validate() && $threshold->hasErrors('currency')
            ?: 'an impossible combination validated';
    });

    // ---------------------------------------------------------------------------------------
    check('an email with no account is ignored, unless the rule asks to create one — and then it gets no signup bonus', function() use ($rewards, $rulesService, $couponsService, $accounts, $storeId, $tag) {
        global $createdUsers;

        $signup = makeRule('guestSignupBonus', ['event' => Rule::EVENT_SIGNUP, 'calculation' => Rule::CALC_FIXED, 'rate' => 100]);
        $rule = makeRule('guestWelcome', [
            'event' => Rule::EVENT_CUSTOM,
            'eventHandle' => 'campaign:verified',
            'currency' => Rule::CURRENCY_COUPON,
            'couponAmount' => 10,
        ]);
        onlyRules([$signup, $rule]);
        $email = strtolower($tag) . '-subscriber@pointz.example';

        $ignored = $rewards->awardEvent($email, 'campaign:verified');
        $noAccount = User::find()->email($email)->status(null)->one() === null;

        $rule->createAccount = true;
        $rulesService->saveRule($rule);
        $rulesService->clearMemo();

        $paid = $rewards->awardEvent($email, 'campaign:verified');
        $user = User::find()->email($email)->status(null)->one();

        if ($user !== null) {
            $createdUsers[] = $user;
        }

        $again = $rewards->awardEvent(strtoupper($email), 'campaign:verified');
        $accounts->clearMemo();
        $points = $user ? ($accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0) : null;
        $coupons = $user ? $couponsService->getCoupons(['userId' => $user->id]) : [];

        return $ignored === [] && $noAccount && count($paid) === 1 && $user !== null && $user->getStatus() === User::STATUS_INACTIVE
            && $again === [] && count($coupons) === 1 && $points == 0.0
            ?: json_encode(['ignored' => count($ignored), 'noAccount' => $noAccount, 'paid' => count($paid), 'status' => $user?->getStatus(), 'again' => count($again), 'coupons' => count($coupons), 'points' => $points]);
    });

    check('an email for a handle no account-creating rule answers creates nothing', function() use ($rewards, $tag) {
        $email = strtolower($tag) . '-stranger@pointz.example';
        $result = $rewards->awardEvent($email, 'campaign:somethingElse');

        return $result === [] && User::find()->email($email)->status(null)->one() === null ?: 'an account was created';
    });

    section('Coupons');

    check('a coupon rule creates its Commerce discount when it is saved', function() use ($commerce) {
        $rule = makeRule('welcomeCoupon', [
            'event' => Rule::EVENT_SIGNUP,
            'currency' => Rule::CURRENCY_COUPON,
            'couponType' => Rule::COUPON_PERCENT,
            'couponAmount' => 10,
            'couponValidDays' => 90,
            'enabled' => false,
        ]);
        $discount = $rule->couponDiscountId ? $commerce->getDiscounts()->getDiscountById($rule->couponDiscountId) : null;

        if ($discount === null) {
            return 'no discount';
        }

        return $discount->requireCouponCode && abs($discount->percentDiscount + 0.1) < 0.0001
            ?: 'discount is ' . json_encode(['code' => $discount->requireCouponCode, 'pct' => $discount->percentDiscount]);
    });

    check('a signup rule can hand out a coupon, once', function() use ($earning, $couponsService, $rulesService, $tag, $storeId) {
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        onlyRules([$rule]);
        $user = makeUser('welcome');
        // Saving a new user already fired the signup bonus.
        $earning->awardSignup($user);
        $coupons = $couponsService->getCoupons(['userId' => $user->id]);

        if (count($coupons) !== 1) {
            return count($coupons) . ' coupons';
        }

        $coupon = $coupons[0];
        $days = (int)round(($coupon->dateExpires->getTimestamp() - time()) / 86400);

        return str_starts_with($coupon->code, 'PZ-') && $coupon->amount == 10.0 && $days === 90
            ?: json_encode(['code' => $coupon->code, 'amount' => $coupon->amount, 'days' => $days]);
    });

    $couponOwner = makeUser('couponOwner');
    $couponStranger = makeUser('couponStranger');
    $promoVariant = makeProduct($tag . '-PROMO', 25.00, true)->getVariants()[0];

    check('a coupon discounts its owner’s cart', function() use ($couponsService, $rulesService, $couponOwner, $promoVariant, $tag, $storeId) {
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        $coupon = $couponsService->issue($rule, $couponOwner, $storeId);

        $cart = makeCart($couponOwner, $promoVariant, 2);
        $cart->couponCode = $coupon->code;
        Craft::$app->getElements()->saveElement($cart, false);

        return abs($cart->getTotalDiscount() + 5.0) < 0.001 ?: 'discount ' . $cart->getTotalDiscount();
    });

    check('somebody else’s coupon does nothing on a stranger’s cart', function() use ($couponsService, $couponOwner, $couponStranger, $promoVariant, $storeId) {
        $coupon = $couponsService->getCoupons(['userId' => $couponOwner->id, 'status' => 'active'])[0];

        $cart = makeCart($couponStranger, $promoVariant, 2);
        $cart->couponCode = $coupon->code;
        Craft::$app->getElements()->saveElement($cart, false);

        return abs($cart->getTotalDiscount()) < 0.001 ?: 'discount ' . $cart->getTotalDiscount();
    });

    check('completing the order marks the coupon used, and Commerce counts the use', function() use ($couponsService, $couponOwner, $promoVariant) {
        $coupon = $couponsService->getCoupons(['userId' => $couponOwner->id, 'status' => 'active'])[0];

        $cart = makeCart($couponOwner, $promoVariant, 1);
        $cart->couponCode = $coupon->code;
        Craft::$app->getElements()->saveElement($cart, false);
        $order = completeOrder($cart);

        $fresh = $couponsService->getCouponById($coupon->id);
        $uses = (new Query())->select(['uses'])->from('{{%commerce_coupons}}')->where(['id' => $coupon->couponId])->scalar();

        return $fresh->status === 'used' && $fresh->orderId === $order->id && (int)$uses === 1
            ?: json_encode(['status' => $fresh->status, 'orderId' => $fresh->orderId, 'uses' => $uses]);
    });

    check('an expired coupon stops discounting before the sweep, and the sweep deletes its code', function() use ($couponsService, $rulesService, $couponOwner, $promoVariant, $tag, $storeId) {
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        $coupon = $couponsService->issue($rule, $couponOwner, $storeId);
        craft\helpers\Db::update(Table::COUPONS, ['dateExpires' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $coupon->id]);

        $cart = makeCart($couponOwner, $promoVariant, 2);
        $cart->couponCode = $coupon->code;
        Craft::$app->getElements()->saveElement($cart, false);
        $discounted = abs($cart->getTotalDiscount()) > 0.001;

        $expired = $couponsService->expireDue();
        $fresh = $couponsService->getCouponById($coupon->id);
        $codeLeft = (new Query())->from('{{%commerce_coupons}}')->where(['code' => $coupon->code])->exists();

        if ($discounted) {
            return 'an expired code still discounted';
        }

        return $expired >= 1 && $fresh->status === 'expired' && !$codeLeft
            ?: json_encode(['expired' => $expired, 'status' => $fresh->status, 'codeLeft' => $codeLeft]);
    });

    check('a reminder is sent once, and a handler can take it over', function() use ($couponsService, $rulesService, $couponOwner, $tag, $storeId) {
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        $coupon = $couponsService->issue($rule, $couponOwner, $storeId);
        craft\helpers\Db::update(Table::COUPONS, ['dateRemind' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 minute'))], ['id' => $coupon->id]);

        $seen = [];
        $handler = static function(justinholtweb\pointz\events\CouponEvent $event) use (&$seen) {
            $seen[] = $event->coupon->id;
            $event->isValid = false;
        };
        $couponsService->on(justinholtweb\pointz\services\Coupons::EVENT_BEFORE_REMIND, $handler);

        try {
            $couponsService->remindDue();
            $couponsService->remindDue();
        } finally {
            $couponsService->off(justinholtweb\pointz\services\Coupons::EVENT_BEFORE_REMIND, $handler);
        }

        $fresh = $couponsService->getCouponById($coupon->id);

        $times = array_count_values($seen)[$coupon->id] ?? 0;

        return $times === 1 && $fresh->dateReminded !== null ?: "reminded $times times";
    });

    check('changing a coupon’s value starts a new discount and leaves issued codes their old one', function() use ($couponsService, $rulesService, $couponOwner, $commerce, $tag, $storeId) {
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        $oldDiscountId = $rule->couponDiscountId;
        $held = $couponsService->getCoupons(['userId' => $couponOwner->id, 'status' => 'active'])[0];

        $rule->couponAmount = 15;
        $rulesService->saveRule($rule);
        $rulesService->clearMemo();
        $rule = $rulesService->getRuleByHandle($tag . 'WelcomeCoupon', $storeId);
        $new = $commerce->getDiscounts()->getDiscountById($rule->couponDiscountId);
        $oldStillThere = $commerce->getDiscounts()->getDiscountById($oldDiscountId) !== null;

        if ($rule->couponDiscountId === $oldDiscountId || abs($new->percentDiscount + 0.15) > 0.0001 || !$oldStillThere) {
            return json_encode(['old' => $oldDiscountId, 'new' => $rule->couponDiscountId, 'oldStillThere' => $oldStillThere]);
        }

        // Once nobody holds a live code on the old discount, the sweep removes it.
        foreach ($couponsService->getCoupons(['discountId' => $oldDiscountId, 'status' => 'active']) as $coupon) {
            $couponsService->revoke($coupon);
        }

        foreach ($couponsService->getCoupons(['userId' => $couponOwner->id, 'status' => 'active']) as $coupon) {
            if ($coupon->discountId === $oldDiscountId) {
                $couponsService->revoke($coupon);
            }
        }

        $removed = $couponsService->cleanUpDiscounts();

        return $removed >= 1 && $commerce->getDiscounts()->getDiscountById($oldDiscountId) === null && $held->discountId === $oldDiscountId
            ?: "removed $removed";
    });

    check('a code whose discount was deleted in Commerce stops being offered', function() use ($couponsService, $rulesService, $couponStranger, $commerce, $tag, $storeId) {
        $rule = makeRule('doomedCoupon', ['event' => Rule::EVENT_CUSTOM, 'eventHandle' => 'doomed', 'currency' => Rule::CURRENCY_COUPON, 'couponAmount' => 5]);
        $coupon = $couponsService->issue($rule, $couponStranger, $storeId);
        $commerce->getDiscounts()->deleteDiscountById($rule->couponDiscountId);

        $couponsService->expireDue();
        $fresh = $couponsService->getCouponById($coupon->id);

        return $fresh->status === 'revoked' && !$fresh->getIsUsable() ?: 'status ' . $fresh->status;
    });

    // ---------------------------------------------------------------------------------------
    section('Thresholds');

    check('a spending threshold buys a coupon each time the balance reaches it', function() use ($grants, $accounts, $couponsService, $ledger, $storeId) {
        $rule = makeRule('fiftyForTen', [
            'event' => Rule::EVENT_THRESHOLD,
            'thresholdPoints' => 50,
            'thresholdSpend' => true,
            'currency' => Rule::CURRENCY_COUPON,
            'couponAmount' => 10,
        ]);
        onlyRules([$rule]);
        $user = makeUser('threshold');

        $grants->grant($user->id, $storeId, Rule::CURRENCY_POINTS, 120, 'fixture');
        $accounts->clearMemo();

        $coupons = $couponsService->getCoupons(['userId' => $user->id, 'ruleId' => $rule->id]);
        $balance = $accounts->getAccount($user->id, $storeId)->pointsBalance;
        $spends = $ledger->getTransactionsQuery(['userId' => $user->id, 'kind' => Transaction::KIND_REWARD])->count();

        return count($coupons) === 2 && $balance == 20.0 && (int)$spends === 2 && $coupons[0]->transactionId !== null
            ?: json_encode(['coupons' => count($coupons), 'balance' => $balance, 'spends' => $spends]);
    });

    check('a threshold that keeps the points fires once, ever', function() use ($grants, $accounts, $storeId) {
        $rule = makeRule('hundredClub', [
            'event' => Rule::EVENT_THRESHOLD,
            'thresholdPoints' => 100,
            'thresholdSpend' => false,
            'currency' => Rule::CURRENCY_CREDIT,
            'rate' => 5,
        ]);
        onlyRules([$rule]);
        $user = makeUser('club');

        $grants->grant($user->id, $storeId, Rule::CURRENCY_POINTS, 150, 'fixture');
        $grants->grant($user->id, $storeId, Rule::CURRENCY_POINTS, 150, 'fixture');
        $accounts->clearMemo();
        $account = $accounts->getAccount($user->id, $storeId);

        return $account->pointsBalance == 300.0 && $account->creditBalance == 5.0
            ?: json_encode(['points' => $account->pointsBalance, 'credit' => $account->creditBalance]);
    });

    check('points released from a hold can cross a threshold', function() use ($ledger, $lifecycle, $accounts, $couponsService, $storeId) {
        $rule = makeRule('heldThreshold', [
            'event' => Rule::EVENT_THRESHOLD,
            'thresholdPoints' => 40,
            'currency' => Rule::CURRENCY_COUPON,
            'couponAmount' => 5,
            'couponType' => Rule::COUPON_FIXED,
        ]);
        onlyRules([$rule]);
        $user = makeUser('heldThreshold');

        $ledger->credit($user->id, $storeId, Rule::CURRENCY_POINTS, 45, [
            'pending' => true,
            'dateAvailable' => new DateTime('-1 minute'),
        ]);
        $before = count($couponsService->getCoupons(['userId' => $user->id]));
        $lifecycle->promoteDueLots();
        $after = $couponsService->getCoupons(['userId' => $user->id]);
        $accounts->clearMemo();

        return $before === 0 && count($after) === 1 && $after[0]->type === 'fixed' && $accounts->getAccount($user->id, $storeId)->pointsBalance == 5.0
            ?: json_encode(['before' => $before, 'after' => count($after)]);
    });

    check('tiers: the higher threshold first, with stop-after, takes precedence', function() use ($grants, $couponsService, $rulesService, $storeId) {
        $twenty = makeRule('tierTwenty', ['event' => Rule::EVENT_THRESHOLD, 'thresholdPoints' => 100, 'currency' => Rule::CURRENCY_COUPON, 'couponAmount' => 20, 'stopProcessing' => true]);
        $ten = makeRule('tierTen', ['event' => Rule::EVENT_THRESHOLD, 'thresholdPoints' => 50, 'currency' => Rule::CURRENCY_COUPON, 'couponAmount' => 10]);
        $rulesService->reorderRules([$twenty->id, $ten->id]);
        onlyRules([$twenty, $ten]);
        $user = makeUser('tiers');

        $grants->grant($user->id, $storeId, Rule::CURRENCY_POINTS, 100, 'fixture');
        $coupons = $couponsService->getCoupons(['userId' => $user->id]);

        return count($coupons) === 1 && $coupons[0]->amount == 20.0
            ?: json_encode(array_map(static fn($c) => $c->amount, $coupons));
    });

    // ---------------------------------------------------------------------------------------
    section('Reviews and birthdays');

    $reviewClass = 'justinholtweb\stars\elements\Review';
    $createdReviews = [];

    $makeReview = static function(User $user, ?int $entryId, ?string $text, string $status = 'approved') use ($reviewClass, &$createdReviews) {
        $review = new $reviewClass();
        $review->reviewerName = 'Pointz fixture';
        $review->reviewerEmail = $user->email;
        $review->rating = 5;
        $review->reviewText = $text;
        $review->entryId = $entryId;
        $review->reviewStatus = $status;

        if (!Craft::$app->getElements()->saveElement($review)) {
            throw new RuntimeException('Could not save review: ' . json_encode($review->getErrors()));
        }

        $createdReviews[] = $review;

        return $review;
    };

    check('an approved Stars review pays once, however often it is saved', function() use ($makeReview, $accounts, $storeId, $reviewClass) {
        if (!class_exists($reviewClass)) {
            return true;
        }

        $rule = makeRule('review', ['event' => Rule::EVENT_REVIEW, 'rate' => 10]);
        onlyRules([$rule]);
        $user = makeUser('reviewer');

        $pending = $makeReview($user, null, 'Lovely book.', 'pending');
        $accounts->clearMemo();
        $beforeApproval = $accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0;

        $pending->reviewStatus = 'approved';
        Craft::$app->getElements()->saveElement($pending);
        Craft::$app->getElements()->saveElement($pending);
        $accounts->clearMemo();
        $after = $accounts->getAccount($user->id, $storeId)?->pointsBalance;

        return $beforeApproval == 0.0 && $after == 10.0 ?: "before $beforeApproval, after $after";
    });

    check('a review without text does not count when the rule asks for text', function() use ($makeReview, $accounts, $storeId, $reviewClass) {
        if (!class_exists($reviewClass)) {
            return true;
        }

        $rule = makeRule('reviewText', ['event' => Rule::EVENT_REVIEW, 'rate' => 10, 'reviewRequiresText' => true]);
        onlyRules([$rule]);
        $user = makeUser('starsOnly');
        $makeReview($user, null, '   ');
        $accounts->clearMemo();

        return ($accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0) == 0.0 ?: 'a bare rating was paid';
    });

    check('“purchased products only” pays for a product the customer bought and not for one they did not', function() use ($makeReview, $accounts, $storeId, $reviewClass, $variantA, $product, $productB) {
        if (!class_exists($reviewClass)) {
            return true;
        }

        $rule = makeRule('reviewBought', ['event' => Rule::EVENT_REVIEW, 'rate' => 10, 'reviewPurchasedOnly' => true]);
        onlyRules([$rule]);
        $user = makeUser('buyer');
        completeOrder(makeCart($user, $variantA, 1));

        $makeReview($user, $productB->id, 'Never bought this.');
        $accounts->clearMemo();
        $notBought = $accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0;

        $makeReview($user, $product->id, 'Bought this one.');
        $accounts->clearMemo();
        $bought = $accounts->getAccount($user->id, $storeId)?->pointsBalance ?? 0.0;

        return $notBought == 0.0 && $bought == 10.0 ?: "not bought $notBought, bought $bought";
    });

    check('a birthday is paid once a year, in its grace window, and 29 February falls on the 28th', function() use ($rewards, $accounts, $couponsService, $storeId) {
        $field = 'mzPromoCity';

        if (Craft::$app->getFields()->getFieldByHandle($field) === null) {
            return true;
        }

        $rule = makeRule('birthday', [
            'event' => Rule::EVENT_BIRTHDAY,
            'birthdayField' => $field,
            'currency' => Rule::CURRENCY_COUPON,
            'couponAmount' => 14.99,
            'couponValidDays' => 90,
        ]);
        onlyRules([$rule]);

        $today = new DateTime('2026-10-03', new DateTimeZone(Craft::$app->getTimeZone()));
        $due = makeUser('birthdayDue');
        $due->setFieldValue($field, '1990-10-01');
        Craft::$app->getElements()->saveElement($due);
        $later = makeUser('birthdayLater');
        $later->setFieldValue($field, '1985-12-25');
        Craft::$app->getElements()->saveElement($later);

        $paid = $rewards->runBirthdays($today);
        $again = $rewards->runBirthdays($today);
        $leap = $rewards->birthdayThisYear('2000-02-29', $today)->format('m-d');

        $dueCoupons = $couponsService->getCoupons(['userId' => $due->id]);
        $laterCoupons = $couponsService->getCoupons(['userId' => $later->id]);

        return $paid === 1 && $again === 0 && count($dueCoupons) === 1 && $laterCoupons === [] && $leap === '02-28'
            && $dueCoupons[0]->reference === 'birthday:2026'
            ?: json_encode(['paid' => $paid, 'again' => $again, 'due' => count($dueCoupons), 'later' => count($laterCoupons), 'leap' => $leap]);
    });


    section('Import');

    $importer = $plugin->getImport();
    $importA = makeUser('importA');
    $importB = makeUser('importB');
    $importBatch = null;
    $openingFile = writeCsv([
        'User,Amount,Currency,Expires,Note',
        $importA->email . ',100,,' . (new DateTime('+1 year'))->format('Y-m-d') . ',',
        $importA->id . ',50,,,',
        strtoupper($importB->email) . ',30,points,,Carried over',
        'nobody-' . $tag . '@pointz.example,10,,,',
        $importA->email . ',20,,2020-01-01,',
        $importA->email . ',-5,,,',
    ]);

    $runOpening = static function() use ($importer, $openingFile) {
        return $importer->importBalances($importer->readCsv($openingFile), ['source' => substr(sha1_file($openingFile), 0, 16)]);
    };

    check('opening balances arrive as lots with their own expiry, and cross no threshold', function() use ($runOpening, $accounts, $couponsService, $importA, $importB, $storeId, &$importBatch) {
        $rule = makeRule('importThreshold', [
            'event' => Rule::EVENT_THRESHOLD,
            'thresholdPoints' => 50,
            'thresholdSpend' => true,
            'currency' => Rule::CURRENCY_COUPON,
            'couponAmount' => 10,
        ]);
        onlyRules([$rule]);

        $result = $runOpening();
        $importBatch = $result['batchId'];
        $accounts->clearMemo();

        $a = $accounts->getAccount($importA->id, $storeId)?->pointsBalance;
        $b = $accounts->getAccount($importB->id, $storeId)?->pointsBalance;
        $dated = array_values(array_filter(lotRows($importA->id, $storeId), static fn($lot) => $lot['dateExpires'] !== null));
        $kinds = (new Query())->select(['kind'])->distinct()->from(Table::TRANSACTIONS)->where(['userId' => [$importA->id, $importB->id]])->column();
        $coupons = $couponsService->getCoupons(['userId' => $importA->id]);

        return $result['imported'] === 3 && array_keys($result['failed']) === [5, 7] && array_keys($result['skipped']) === [6]
            && $a == 150.0 && $b == 30.0 && count($dated) === 1 && (float)$dated[0]['amount'] == 100.0
            && $kinds === [Transaction::KIND_IMPORT] && $coupons === []
            ?: json_encode(['result' => $result, 'a' => $a, 'b' => $b, 'dated' => count($dated), 'kinds' => $kinds, 'coupons' => count($coupons)]);
    });

    check('running the same file again imports nothing', function() use ($runOpening, $accounts, $importA, $storeId) {
        $result = $runOpening();
        $accounts->clearMemo();
        $a = $accounts->getAccount($importA->id, $storeId)?->pointsBalance;

        return $result['imported'] === 0 && count($result['skipped']) === 4 && $a == 150.0
            ?: json_encode(['imported' => $result['imported'], 'skipped' => $result['skipped'], 'a' => $a]);
    });

    check('a reverted import takes its balances back, and the file can then be run again', function() use ($runOpening, $importer, $accounts, $importA, $importB, $storeId, &$importBatch) {
        $reverted = $importer->revert($importBatch);
        $accounts->clearMemo();
        $after = [$accounts->getAccount($importA->id, $storeId)?->pointsBalance, $accounts->getAccount($importB->id, $storeId)?->pointsBalance];

        $again = $runOpening();
        $accounts->clearMemo();
        $a = $accounts->getAccount($importA->id, $storeId)?->pointsBalance;

        return $reverted['reversed'] === 3 && $after == [0.0, 0.0] && $again['imported'] === 3 && $a == 150.0
            ?: json_encode(['reverted' => $reverted, 'after' => $after, 'again' => $again['imported'], 'a' => $a]);
    });

    check('a reference column makes a corrected file safe to run again', function() use ($importer, $accounts, $importB, $storeId, $tag) {
        $before = $accounts->getAccount($importB->id, $storeId)?->pointsBalance ?? 0.0;
        $first = writeCsv(['user,amount,reference', $importB->email . ",10,$tag-1"]);
        $second = writeCsv(['user,amount,reference', $importB->email . ",10,$tag-1", $importB->email . ",5,$tag-2"]);

        $one = $importer->importBalances($importer->readCsv($first), ['source' => substr(sha1_file($first), 0, 16)]);
        $two = $importer->importBalances($importer->readCsv($second), ['source' => substr(sha1_file($second), 0, 16)]);
        $accounts->clearMemo();
        $after = $accounts->getAccount($importB->id, $storeId)?->pointsBalance;

        return $one['imported'] === 1 && $two['imported'] === 1 && count($two['skipped']) === 1 && $after - $before == 15.0
            ?: json_encode(['one' => $one['imported'], 'two' => $two['imported'], 'gained' => $after - $before]);
    });

    $legacy = makeLegacyDiscount('Legacy loyalty ' . $tag, ['legacy-' . strtolower($tag)], 10, new DateTime('+20 days'));

    check('an outstanding Commerce coupon is taken over: its owner keeps it and nobody else can use it', function() use ($importer, $couponsService, $legacy, $importA, $couponStranger, $promoVariant, $tag) {
        $code = 'LEGACY-' . strtoupper($tag);
        $file = writeCsv(['code,email,issued', "$code,{$importA->email},2024-05-01"]);
        $result = $importer->importCoupons($importer->readCsv($file), ['remindDays' => 7]);

        $coupon = $couponsService->getCouponByCode($code);
        $maxUses = (new Query())->select(['maxUses'])->from('{{%commerce_coupons}}')->where(['discountId' => $legacy->id])->scalar();

        $mine = makeCart($importA, $promoVariant, 2);
        $mine->couponCode = $code;
        Craft::$app->getElements()->saveElement($mine, false);
        $theirs = makeCart($couponStranger, $promoVariant, 2);
        $theirs->couponCode = $code;
        Craft::$app->getElements()->saveElement($theirs, false);

        $expiresOk = $coupon && abs($coupon->dateExpires->getTimestamp() - $legacy->dateTo->getTimestamp()) < 2;
        $remindOk = $coupon && abs(($coupon->dateExpires->getTimestamp() - $coupon->dateRemind->getTimestamp()) - 7 * 86400) < 2;

        return $result['imported'] === 1 && $result['madeSingleUse'] === 1 && (int)$maxUses === 1
            && $coupon->userId === $importA->id && $coupon->ruleId === null && $coupon->type === Rule::COUPON_PERCENT && $coupon->amount == 10.0
            && $expiresOk && $remindOk && $coupon->dateCreated->format('Y-m-d') === '2024-05-01'
            && abs($mine->getTotalDiscount() + 5.0) < 0.001 && abs($theirs->getTotalDiscount()) < 0.001
            ?: json_encode(['result' => $result, 'maxUses' => $maxUses, 'coupon' => $coupon?->toArray(), 'mine' => $mine->getTotalDiscount(), 'theirs' => $theirs->getTotalDiscount()]);
    });

    check('a discount with codes the file does not list is refused whole', function() use ($importer, $couponsService, $importA, $tag) {
        makeLegacyDiscount('Shared ' . $tag, ["shared-a-$tag", "shared-b-$tag"], 15);
        $file = writeCsv(['code,user', "shared-a-$tag,{$importA->id}"]);
        $result = $importer->importCoupons($importer->readCsv($file));

        return $result['imported'] === 0 && count($result['failed']) === 1 && $couponsService->getCouponByCode("shared-a-$tag") === null
            ?: json_encode($result);
    });

    check('reverting a coupon import hands unused codes back to Commerce untouched', function() use ($importer, $couponsService, $importA, $tag) {
        $discount = makeLegacyDiscount('Revertible ' . $tag, ["revert-$tag"], 5);
        $file = writeCsv(['code,user', "revert-$tag,{$importA->id}"]);
        $result = $importer->importCoupons($importer->readCsv($file));
        $managedBefore = in_array($discount->id, $couponsService->getManagedDiscountIds(), true);

        $reverted = $importer->revert($result['batchId']);
        $managedAfter = in_array($discount->id, $couponsService->getManagedDiscountIds(), true);
        $codeLeft = (new Query())->from('{{%commerce_coupons}}')->where(['discountId' => $discount->id])->exists();

        return $result['imported'] === 1 && $managedBefore && !$managedAfter && $reverted['released'] === 1 && $codeLeft
            && $couponsService->getCouponByCode("revert-$tag") === null
            ?: json_encode(['result' => $result, 'before' => $managedBefore, 'after' => $managedAfter, 'reverted' => $reverted, 'codeLeft' => $codeLeft]);
    });

    check('a taken-over code is skipped on a second run, and once it expires the sweep deletes it and its discount', function() use ($importer, $couponsService, $commerce, $legacy, $importA, $tag) {
        $code = 'LEGACY-' . strtoupper($tag);
        $again = $importer->importCoupons($importer->readCsv(writeCsv(['code,email', "$code,{$importA->email}"])));

        $coupon = $couponsService->getCouponByCode($code);
        craft\helpers\Db::update(Table::COUPONS, ['dateExpires' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $coupon->id]);
        $couponsService->expireDue();
        $couponsService->cleanUpDiscounts();

        $fresh = $couponsService->getCouponById($coupon->id);
        $discountLeft = $commerce->getDiscounts()->getDiscountById($legacy->id) !== null;

        return $again['imported'] === 0 && count($again['skipped']) === 1 && $fresh->status === 'expired' && !$discountLeft
            ?: json_encode(['again' => $again, 'status' => $fresh->status, 'discountLeft' => $discountLeft]);
    });
} finally {
    $plugin->edition = $originalEdition;
    Plugin::getInstance()->setSettings($originalSettings->toArray());

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $order) {
        $fresh = Order::find()->id($order->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdProducts as $product) {
        $fresh = Product::find()->id($product->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdReviews ?? [] as $review) {
        $elements->deleteElement($review, true);
    }

    // The discounts coupon rules created, current and replaced, so nothing outlives the run.
    $userIds = array_map(static fn(User $user) => $user->id, $createdUsers);
    $ruleIds = array_map(static fn(Rule $rule) => $rule->id, $createdRules);
    $discountIds = array_unique(array_merge(
        (new Query())->select(['discountId'])->from(Table::COUPONS)->where(['userId' => $userIds ?: [0]])->andWhere(['not', ['discountId' => null]])->column(),
        (new Query())->select(['couponDiscountId'])->from(Table::RULES)->where(['id' => $ruleIds ?: [0]])->andWhere(['not', ['couponDiscountId' => null]])->column(),
    ));

    foreach ($createdRules as $rule) {
        Plugin::getInstance()->getRules()->deleteRuleById($rule->id);
    }

    // Deleting the customer cascades the ledger, the lots and the account with it.
    foreach ($createdUsers as $user) {
        $fresh = User::find()->id($user->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($tempFiles as $path) {
        @unlink($path);
    }

    foreach (array_unique(array_merge($discountIds, $createdDiscounts)) as $discountId) {
        Commerce::getInstance()->getDiscounts()->deleteDiscountById((int)$discountId);
    }
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
