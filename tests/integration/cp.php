<?php
/**
 * The control-panel screens, over HTTP, as an admin would open them.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/tests/integration/cp.php
 *
 * A screen that extends the CP layout can't be rendered from the console (Craft's console request
 * has no `getBodyParam()`, and the layout calls it), so `checks.php` can only compile them. This
 * opens each one for real. It's what caught the ledger's Export CSV button, which linked to a CP
 * page that doesn't exist and had answered 404 since 5.0.
 *
 * Needs Pointz Pro in project config (the harness default). Self-cleaning: the admin it signs in
 * as is hard-deleted at the end, and their ledger rows go with them.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
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

$admin = new User(['username' => "pointz-admin-$run", 'email' => "pointz-admin-$run@example.com", 'newPassword' => $password, 'admin' => true]);
Craft::$app->getElements()->saveElement($admin, false);
Craft::$app->getUsers()->activateUser($admin);

register_shutdown_function(function() use ($admin) {
    Craft::$app->getElements()->deleteElement($admin, true);
});

$plugin->getLedger()->credit($admin->id, $storeId, Rule::CURRENCY_POINTS, 11, ['kind' => Transaction::KIND_ADJUST, 'note' => "cp.php $run"]);

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
$http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $admin->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

$today = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
$local = explode('@', $admin->email)[0];

/**
 * Opens a CP URL and checks it answers 200 with every needle in the body.
 */
$opens = static function(string $url, array $needles) use ($http): bool|string {
    $response = $http->get("index.php?p=$url");
    $body = (string)$response->getBody();
    $missing = array_values(array_filter($needles, static fn(string $needle) => !str_contains($body, $needle)));

    if ($response->getStatusCode() === 200 && !$missing) {
        return true;
    }

    preg_match('/<title>(.*?)<\/title>/s', $body, $title);

    return $response->getStatusCode() . ' ' . trim(strip_tags($title[1] ?? '')) . ($missing ? ' missing ' . json_encode($missing) : '');
};

echo "\nLedger\n";

check('filters by part of an email and a range of days, and the export link carries them', fn() => $opens(
    "admin/pointz/ledger&search=$local&from=$today&to=$today",
    [$admin->email, "value=\"$today\"", 'actions/pointz/ledger/export', "search=$local"],
));

check('an impossible date is ignored rather than failing', fn() => $opens("admin/pointz/ledger&from=2026-02-31&search=$local", [$admin->email]));

check('a range with nothing in it says so', fn() => $opens("admin/pointz/ledger&search=$local&to=2001-01-01", ['Nothing matches those filters']));

check('the CSV export answers, with only what the filters match', function() use ($http, $local, $today, $admin, $run) {
    $response = $http->get("index.php?p=admin/actions/pointz/ledger/export&search=$local&from=$today");
    $lines = array_values(array_filter(explode("\n", (string)$response->getBody())));

    return $response->getStatusCode() === 200 && count($lines) === 2 && str_contains($lines[1], $admin->email) && str_contains($lines[1], "cp.php $run")
        ?: $response->getStatusCode() . ', ' . count($lines) . ' lines';
});

echo "\nCoupons\n";

check('the index opens with the period counts and the panel placeholder', fn() => $opens('admin/pointz/coupons', ['pointz-due']));

check('the going-out-today panel loads, from the CP and from the site URL', function() use ($opens) {
    $needles = ['Going out today', 'Birthday rewards', 'Expiry reminders', 'Reminders after issue'];
    $cp = $opens('admin/actions/pointz/coupons/due', $needles);
    $site = $opens('actions/pointz/coupons/due', $needles);

    return $cp === true && $site === true ?: json_encode(['cp' => $cp, 'site' => $site]);
});

echo "\nSettings and a customer\n";

check('the settings screen offers the member group', fn() => $opens('admin/settings/plugins/pointz', ['name="settings[membershipGroup]"']));

check('a customer’s balance page opens', fn() => $opens("admin/pointz/balances/{$admin->id}", ['Lifetime', "cp.php $run"]));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
