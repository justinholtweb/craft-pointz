---
title: Twig and events
slug: twig
order: 40
summary: Everything on craft.pointz, and the two events for logic the rule builder cannot express.
---

## craft.pointz

Every method defaults to the logged-in customer and the current cart, and takes them explicitly
when it should not. **Nothing on this variable writes.** A preview cannot spend a point, a quote
cannot move a balance, and no amount of template refreshing can award anything twice.

### Balances

```twig
craft.pointz.balance()                    {# spendable points #}
craft.pointz.balance(user, storeId)
craft.pointz.pendingBalance()             {# earned but still on hold #}
craft.pointz.creditBalance()
craft.pointz.account()                    {# the whole Account model, or null #}
craft.pointz.ledger(25)                   {# the customer's history, newest first #}
craft.pointz.expiring(30)                 {# what is about to expire, and when #}
```

### Redeeming

```twig
craft.pointz.quote()                      {# what the cart's intent comes to #}
craft.pointz.quote(cart, 500)             {# a hypothetical, applying nothing #}
craft.pointz.maxRedeemable()              {# most points this cart could take #}
craft.pointz.cart()                       {# the cart, without creating one #}
```

A quote carries `points`, `pointsValue`, `credit`, `maxPoints`, `maxCredit`, `pointsBalance`,
`creditBalance`, `base`, `cap`, `totalDiscount`, `wasClamped` and `notices` — the last of which is
already written in the customer's language, ready to print.

The balances and maxima are only filled in for the cart's own customer, signed in. Anyone else —
including a guest who has typed a registered customer's email at checkout — sees zeroes, and can't
redeem.

### Earning

```twig
craft.pointz.willEarn()                   {# an Award for the current cart #}
craft.pointz.earnFor(variant, 2)          {# an Award for a product page #}
craft.pointz.activeRules()                {# the rules a shop might want to advertise #}
```

An `Award` has `points`, `credit`, `lines` (one per contributing rule, with `ruleName`, `amount`,
`basis` and any `note` explaining a cap) and `notices`.

### Formatting

```twig
craft.pointz.format(1240)                 {# "1,240" #}
craft.pointz.formatted(1240)              {# "1,240 points" — the store's own word #}
craft.pointz.label(1)                     {# "point" #}
craft.pointz.formatMoney(12.5)
craft.pointz.value(1000)                  {# points as money #}
craft.pointz.points(10)                   {# money as points #}
craft.pointz.settings
craft.pointz.isPro
```

## Site actions

| Action | Body | Answers |
| --- | --- | --- |
| `pointz/cart/redeem` | `points`, `credit` (either may be `max`) | JSON with `success`, `message`, `quote`; or a redirect with a flash. Needs the cart's customer to be signed in. |
| `pointz/cart/remove` | — | The same |
| `pointz/cart/quote` | `points`, `credit` as query params | JSON `quote`, applying nothing |

## Events

Two, both in PHP, for the logic a rule builder cannot express.

### Changing an award before it is written

```php
use justinholtweb\pointz\events\AwardEvent;
use justinholtweb\pointz\models\AwardLine;
use justinholtweb\pointz\services\Earning;
use yii\base\Event;

Event::on(
    Earning::class,
    Earning::EVENT_BEFORE_AWARD,
    function(AwardEvent $event) {
        // A tier a rule cannot see: partners earn 50% more.
        if ($event->user?->isInGroup('partners')) {
            foreach ($event->award->lines as $line) {
                $line->amount = floor($line->amount * 1.5);
            }
        }

        // A preview must not have side effects.
        if ($event->isPreview) {
            return;
        }
    }
);
```

`$event->award->clear()` skips the order entirely. The event fires for previews as well as for
real accruals, which is exactly what keeps the two agreeing — check `$event->isPreview` before
doing anything with a side effect.

### Reacting to a movement

```php
use justinholtweb\pointz\events\TransactionEvent;
use justinholtweb\pointz\services\Ledger;
use yii\base\Event;

Event::on(
    Ledger::class,
    Ledger::EVENT_AFTER_TRANSACTION,
    function(TransactionEvent $event) {
        if ($event->transaction->kind === 'expire') {
            // Tell them what they lost.
        }
    }
);
```

The ledger is append-only, so this event is read-only by design: there is nothing to change after
the fact, and a correction is written as its opposite.

## Services

```php
use justinholtweb\pointz\Plugin;

$plugin = Plugin::getInstance();

$plugin->getAccounts()->getBalance($userId, $storeId);
$plugin->getLedger()->credit($userId, $storeId, 'points', 500, ['note' => 'Survey']);
$plugin->getLedger()->debit($userId, $storeId, 'points', 100);
$plugin->getGrants()->grantMany($userIds, $storeId, 'points', 250, 'Launch');
$plugin->getEarning()->evaluateOrder($order, true);
$plugin->getRedemption()->quote($cart, 500);
$plugin->getLifecycle()->expireDueLots();
```

`Ledger` is the only writer. Anything that moves value goes through `credit()`, `debit()`,
`restore()` or `revoke()`, each of which holds a per-account lock and writes inside a database
transaction — so a half-written movement is never visible and two checkouts cannot spend the same
lot.
