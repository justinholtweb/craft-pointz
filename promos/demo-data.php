<?php
/**
 * Demo data for the promo screenshots, written into the plugin-testing harness.
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/promos/demo-data.php
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/promos/demo-data.php clean
 *
 * Every movement goes through the Ledger, like anything real would. The only thing written
 * around it is `dateCreated`, backdated afterwards so the history reads as months rather than
 * seconds. The harness is shared, so `clean` removes every row this made and puts the seeded
 * rule back the way it found it.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\pointz\db\Table;
use justinholtweb\pointz\models\Rule;
use justinholtweb\pointz\models\Transaction;
use justinholtweb\pointz\Plugin;

$plugin = Plugin::getInstance();
$ledger = $plugin->getLedger();
$rules = $plugin->getRules();
$storeId = 1;
$demoHandles = ['doublePointsOnOutdoorGear', 'launchWeekend', 'welcomeBonus'];

$customers = [
    'freya.mikkelsen@rowangray.test',
    'aurelio.santos@rowangray.test',
    'olivier.brandt@rowangray.test',
    'esther.nakamura@rowangray.test',
];

$userIds = [];
foreach ($customers as $email) {
    $user = User::find()->email($email)->status(null)->one();
    if (!$user) {
        fwrite(STDERR, "Missing harness customer $email\n");
        exit(1);
    }
    $userIds[$email] = $user->id;
}

// ---- clean ------------------------------------------------------------------------------------

$clean = function() use ($userIds, $storeId, $rules, $demoHandles) {
    $ids = array_values($userIds);
    $db = Craft::$app->getDb();

    $txIds = (new Query())->select('id')->from(Table::TRANSACTIONS)
        ->where(['userId' => $ids, 'storeId' => $storeId])->column();
    $lotIds = (new Query())->select('id')->from(Table::LOTS)
        ->where(['userId' => $ids, 'storeId' => $storeId])->column();

    if ($lotIds) {
        Db::delete(Table::LOT_USES, ['lotId' => $lotIds]);
        Db::delete(Table::LOTS, ['id' => $lotIds]);
    }
    if ($txIds) {
        $db->createCommand()->update(Table::TRANSACTIONS, ['reversesId' => null], ['id' => $txIds])->execute();
        Db::delete(Table::TRANSACTIONS, ['id' => $txIds]);
    }
    Db::delete(Table::ACCOUNTS, ['userId' => $ids, 'storeId' => $storeId]);

    foreach ($rules->getAllRules($storeId) as $rule) {
        if (in_array($rule->handle, $demoHandles, true)) {
            $rules->deleteRuleById($rule->id);
        }
    }

    // The seeded rule is disabled on install; that is how it is left.
    $seeded = $rules->getRuleById(1);
    if ($seeded && $seeded->enabled) {
        $seeded->enabled = false;
        $rules->saveRule($seeded, false);
    }

    echo "Cleaned " . count($txIds) . " transactions and " . count($lotIds) . " lots.\n";
};

$clean();

if (($argv[1] ?? '') === 'clean') {
    exit(0);
}

// ---- rules: the three from the README --------------------------------------------------------

$seeded = $rules->getRuleById(1);
$seeded->enabled = true;
$seeded->rate = 1;
$seeded->basis = Rule::BASIS_ITEM_SUBTOTAL;
$rules->saveRule($seeded, false);

$outdoor = new Rule([
    'storeId' => $storeId,
    'name' => 'Double points on outdoor gear',
    'handle' => 'doublePointsOnOutdoorGear',
    'enabled' => true,
    'scope' => Rule::SCOPE_LINE_ITEM,
    'basis' => Rule::BASIS_LINE_ITEM_SUBTOTAL,
    'rate' => 1,
    'multiplier' => 2,
]);
$rules->saveRule($outdoor, false);

$fri = new DateTime('next friday 18:00');
$sun = (clone $fri)->modify('+2 days')->setTime(23, 59);
$launch = new Rule([
    'storeId' => $storeId,
    'name' => 'Launch weekend',
    'handle' => 'launchWeekend',
    'enabled' => true,
    'basis' => Rule::BASIS_TOTAL,
    'rate' => 5,
    'dateFrom' => $fri,
    'dateTo' => $sun,
]);
$rules->saveRule($launch, false);

$welcome = new Rule([
    'storeId' => $storeId,
    'name' => 'Welcome bonus',
    'handle' => 'welcomeBonus',
    'enabled' => true,
    'event' => Rule::EVENT_SIGNUP,
    'calculation' => Rule::CALC_FIXED,
    'rate' => 250,
    'expireAfterDays' => 120,
]);
$rules->saveRule($welcome, false);

// ---- movements ---------------------------------------------------------------------------------

$order = fn(string $ref) => Order::find()->reference($ref)->one()?->id;
$when = [];

$stamp = function(Transaction $t, string $date) use (&$when) {
    $when[$t->id] = $date;
    return $t;
};

// Freya: the screenshot. A welcome lot about to expire, dated earnings, a never-expiring apology,
// a spend that takes the welcome lot first, and a refund that puts it back where it came from.
$u = $userIds['freya.mikkelsen@rowangray.test'];
$stamp($ledger->credit($u, $storeId, 'points', 250, [
    'ruleId' => $welcome->id, 'dateExpires' => new DateTime('+19 days'),
]), '-4 months');
$stamp($ledger->credit($u, $storeId, 'points', 33, [
    'orderId' => $order('INV-2026-02102'), 'ruleId' => 1, 'dateExpires' => new DateTime('2027-08-03'),
]), '2026-08-03 11:04');
$stamp($ledger->credit($u, $storeId, 'points', 100, [
    'kind' => Transaction::KIND_ADJUST, 'authorId' => 1, 'note' => 'Sorry about the late delivery',
]), '2026-08-19 15:42');
$stamp($ledger->credit($u, $storeId, 'points', 33, [
    'orderId' => $order('INV-2026-02103'), 'ruleId' => 1, 'dateExpires' => new DateTime('2027-09-03'),
]), '2026-09-03 11:02');
$spend = $stamp($ledger->debit($u, $storeId, 'points', 300, [
    'orderId' => $order('INV-2026-02104'),
]), '2026-09-07 10:24');
$stamp($ledger->credit($u, $storeId, 'points', 33, [
    'orderId' => $order('INV-2026-02104'), 'ruleId' => 1, 'dateExpires' => new DateTime('2027-09-07'),
]), '2026-09-07 10:24');
$stamp($ledger->restore($spend, 120, [
    'note' => 'Returned one item',
]), '2026-09-12 09:15');

// Everyone else: enough for the balances index and the liability to look like a store.
$u = $userIds['aurelio.santos@rowangray.test'];
foreach (['INV-2026-02105' => '2026-07-28 11:00', 'INV-2026-02106' => '2026-08-11 11:00', 'INV-2026-02107' => '2026-08-25 11:00', 'INV-2026-02108' => '2026-08-27 10:24'] as $ref => $date) {
    $stamp($ledger->credit($u, $storeId, 'points', 19, ['orderId' => $order($ref), 'ruleId' => 1, 'dateExpires' => new DateTime('+11 months')]), $date);
}
$stamp($ledger->credit($u, $storeId, 'points', 250, ['ruleId' => $welcome->id, 'dateExpires' => new DateTime('+64 days')]), '-2 months');

$u = $userIds['olivier.brandt@rowangray.test'];
$stamp($ledger->credit($u, $storeId, 'points', 250, ['ruleId' => $welcome->id, 'dateExpires' => new DateTime('+33 days')]), '-3 months');
$stamp($ledger->credit($u, $storeId, 'points', 31, ['orderId' => $order('INV-2026-02100'), 'ruleId' => 1, 'dateExpires' => new DateTime('+10 months')]), '2026-08-03 11:00');
$stamp($ledger->credit($u, $storeId, 'points', 31, ['orderId' => $order('INV-2026-02101'), 'ruleId' => 1, 'dateExpires' => new DateTime('+11 months')]), '2026-09-03 11:00');

$u = $userIds['esther.nakamura@rowangray.test'];
$stamp($ledger->credit($u, $storeId, 'points', 250, ['ruleId' => $welcome->id, 'dateExpires' => new DateTime('+88 days')]), '-1 month');
$stamp($ledger->credit($u, $storeId, 'points', 31, ['orderId' => $order('INV-2026-02099'), 'ruleId' => 1, 'dateExpires' => new DateTime('+11 months')]), '2026-09-03 11:00');

// Backdate. The ledger stamps "now"; a promo wants a history.
foreach ($when as $id => $date) {
    $at = Db::prepareDateForDb(new DateTime($date));
    Db::update(Table::TRANSACTIONS, ['dateCreated' => $at], ['id' => $id], updateTimestamp: false);
    Db::update(Table::LOTS, ['dateCreated' => $at], ['transactionId' => $id], updateTimestamp: false);
}

foreach ($userIds as $email => $id) {
    $a = $plugin->getAccounts()->getAccount($id, $storeId);
    printf("%-34s user %-6d %s pts\n", $email, $id, $a?->pointsBalance);
}
