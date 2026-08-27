---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it changes, and the ones worth thinking about before launch.
---

Settings live at **Settings → Plugins → Pointz**, and apply to the whole install. Earning rules
are per store; the exchange rate, the expiry policy and the refund behaviour are one set of
numbers for the site.

## Earning

| Setting | What it does |
| --- | --- |
| **Award points on completed orders** | The master switch. Off leaves live checkouts alone while keeping manual grants, the console and backfill available. |
| **Award when** | *The order completes* is immediate. *The order is paid in full* and *The order reaches a status* both mean the points arrive only once the money has, which matters if you take offline payments. |
| **Hold new points for** | Days a new balance stays pending before it can be spent — a refund window, in effect. Pending value is visible to you in the control panel and, unless you print it yourself, invisible to the customer. |
| **Guest checkouts earn** | Commerce gives every order a customer account, so a guest's points wait for them to activate it. Off means guests earn nothing at all. |
| **Earn on value paid with points or credit** | Off by default. Paying with points and then earning points on the same money is a loop the store funds twice. |

## Redeeming

| Setting | What it does |
| --- | --- |
| **Points per unit of currency** | The exchange rate. 100 means 100 points buy 1.00. |
| **Minimum redemption** | The fewest points a customer may spend in one order. |
| **Redeem in multiples of** | 1 lets a customer spend any number. 100 rounds every request down to the nearest hundred, which makes the maths legible on the front end. |
| **Maximum share of an order** | The most of the redeemable base points may cover, as a percentage. Empty lets points pay for all of it. |
| **Redeemable against** | Which figure that percentage is measured against — and therefore whether points can pay for shipping and tax. |
| **If the balance falls short** | What to do when an order completes carrying a redemption the balance can no longer cover. See below. |
| **Let customers spend store credit** | Pro. Credit is the customer's own money, so the percentage cap does not apply to it — only the order's remaining value does. |

### The shortfall setting

A cart re-quotes its redemption against the live balance on every recalculation, so the only way
an order can complete asking for more than the customer has is if the balance moved in between —
a second checkout in another tab, a manual deduction, an expiry sweep.

*Spend what is there and note the shortfall* (the default) keeps the checkout working: the
customer gets the discount they were quoted, the ledger records what was actually available, and
a notice goes on the order so the difference is visible rather than silent.

*Fail the order completion* is available and is a real trade rather than a safety feature —
Commerce's `markAsComplete()` has no `try`/`finally` around the event, so a handler that throws
leaves the order's completion mutex held for the rest of that request.

## Expiry

Expiry is opt-in, and it runs from the console rather than from a timestamp:

```sh
php craft pointz/sweep/run
```

| Setting | What it does |
| --- | --- |
| **Expire earned value** | The master switch for everything below. |
| **Expires after** | Days a lot lives, unless its own rule overrides it. |
| **Expire an idle balance after** | Pro. Days with no earning *and* no spending after which the whole balance goes. |
| **Warn this long before expiry** | Pro. How far ahead `craft.pointz.expiring()` and `pointz/sweep/expiring` look. |

An earning rule can set its own **Expires after**, which wins over the site-wide figure for value
that rule awards. That is how "these promotional points are good for 30 days" is written without
shortening anything else.

## Refunds

| Setting | What it does |
| --- | --- |
| **When an order is refunded** | *Take back the refunded share* (default), *Take back everything the order earned*, or *Leave earned value alone*. |
| **Hand back points the order spent** | On by default. A customer who paid partly in points and got their money back is owed the points. |

Reversal is cumulative rather than repeated: two 20% refunds against the same order take 40% in
total, not 20% twice and not 40% the second time. What has already been taken back is netted off.

Points a customer has already spent cannot be taken back — a refund on an order whose points are
gone reports the shortfall rather than pushing a balance negative.

## Wording

The three label settings feed everything that prints a quantity: the cart adjustment, the control
panel, and `craft.pointz.formatted()`. Set them to *credit* / *credits*, *star* / *stars*, or
whatever your scheme is called, and the whole interface follows.

## Operations

| Setting | What it does |
| --- | --- |
| **Lock timeout** | Seconds to wait for another request to finish with a customer's balance. Every movement holds a per-account lock, so two checkouts can never spend the same lot. |
| **Sweep batch size** | How many lots or accounts one expiry or promotion run works through. |
| **Log every movement** | Writes each movement to the logs as well as the ledger. Useful while setting up, noisy afterwards. |

## Config file

Settings can be overridden per environment with `config/pointz.php`, like any Craft plugin:

```php
<?php

return [
    'earningEnabled' => App::env('CRAFT_ENVIRONMENT') === 'production',
    'pointsPerUnit' => 100,
    'expiryEnabled' => true,
    'expireAfterDays' => 365,
];
```

Anything set here is locked in the control panel, which is the point.
