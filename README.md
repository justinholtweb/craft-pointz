# Pointz

Loyalty points and store credit for Craft Commerce 5. Earning rules a merchandiser can write, a
ledger that expires and reverses correctly, and redemption at checkout that never spends anything
until the order completes. Customers can also be rewarded for reviews, birthdays, signups and
milestones, with points, credit or single-use coupons.

WooCommerce shops have had *Points and Rewards* for a decade. Craft has had gift vouchers and
wishlists from Verbb and affiliates from Kickback, and nothing at all for loyalty. Pointz is that
missing piece.

## The one idea

**Balances are derived, never typed in.**

Every movement of value is a row in an append-only ledger, and every positive movement also
creates a *lot* — an amount with its own remaining figure and its own expiry date. Spending
consumes lots soonest-expiry-first and records exactly which lots it took from, so a refund puts
value back where it came from instead of minting new value with a new date.

A balance is the sum of those lots. If it ever looks wrong, one command rebuilds every balance in
the store from the ledger and proves it.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+

## Installation

```sh
composer require justinholtweb/craft-pointz
php craft plugin/install pointz
```

Pointz installs with one **disabled** earning rule per store. Nothing is awarded until you open
**Pointz → Earning rules**, set a rate and switch it on — adding a loyalty plugin should never
start paying out on a live store before anyone has read the numbers.

## Editions

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$129**, $99/year renewal |
| One earning rule per store | ✅ | ✅ |
| Rate-per-value and fixed awards | ✅ | ✅ |
| Signup bonus | ✅ | ✅ |
| Redeem points at checkout — rate, minimum, block size, cap | ✅ | ✅ |
| Balances, ledger and manual grants | ✅ | ✅ |
| Holds, and refund reversal | ✅ | ✅ |
| One site-wide expiry policy | ✅ | ✅ |
| Order panel, Twig API, GraphQL, console | ✅ | ✅ |
| Several rules per store, stacked or exclusive | | ✅ |
| Per-line-item rules with product conditions | | ✅ |
| Order and customer conditions | | ✅ |
| Campaign windows and multipliers | | ✅ |
| Per-rule and per-customer caps | | ✅ |
| **Store credit** — grant, redeem, refund-to-credit | | ✅ |
| Per-rule expiry, inactivity expiry, expiry warnings | | ✅ |
| Backfill with a dry run and an undo | | ✅ |
| Ledger CSV export | | ✅ |
| Liability dashboard widget | | ✅ |
| Rewards for an approved review (Stars), a birthday, a points milestone, or a custom event from Formie, Dispatch or your own code | | ✅ |
| **Coupons as a reward**: single-use, one customer, optional expiry and reminder email, one Commerce discount per rule | | ✅ |

Refund reversal and holds are deliberately in **Lite**. A free edition that over-awards is a bug,
not an upsell.

## What it looks like

**Earning rules** are read top to bottom, and each matching rule adds to the award:

| Rule | Rate | Applies | Window |
| --- | --- | --- | --- |
| Points on every order | 1 per unit of item subtotal | Once per order | Always |
| Double points on outdoor gear | 1 per unit of line subtotal, ×2 | Per matching line item | Always |
| Launch weekend | 5 per unit of order total | Once per order | Fri–Sun |

**And rules for things that aren't an order:**

| Rule | Earned for | Awards |
| --- | --- | --- |
| Welcome | A Formie newsletter signup | A 10% coupon, valid 30 days |
| Thanks for the review | An approved review with text, of something they bought | 10 points |
| Happy birthday | The customer's birthday | A 15% coupon, valid 90 days, reminder 14 days before |
| Club reward | Reaching 500 points, spending them | €5 of store credit |

**Redemption** on the front end is one form:

```twig
{% set quote = craft.pointz.quote() %}

<form method="post">
    {{ csrfInput() }}
    {{ actionInput('pointz/cart/redeem') }}
    <input type="number" name="points" max="{{ quote.maxPoints }}" value="{{ quote.points }}">
    <button>Apply</button>
</form>

<p>You have {{ craft.pointz.formatted(quote.pointsBalance) }}.</p>
```

**And telling customers what they will earn** runs the same rule engine the real accrual runs, so
the promise on the product page is the promise kept in the ledger:

```twig
{% set award = craft.pointz.earnFor(product.defaultVariant) %}
<p>Earn {{ craft.pointz.formatted(award.points) }} with this order.</p>
```

## The parts that are hard, and how they work

**Redemption is intent, not a debit.** A customer's request is stored against the cart and
re-clamped on every recalculation against the live balance and the live order total. An abandoned
cart costs nothing; a cart that sits for a week quotes a smaller discount. The debit happens once,
at completion, for exactly the amount the adjustment says — the points count travels in the
adjustment's snapshot so a rate change between cart and checkout cannot alter what is spent.

**Refunds are cumulative, not repeated.** Two 20% refunds against one order take 40% in total. What
has already been reversed is netted off, and points the customer has already spent are reported as
a shortfall rather than pushing a balance negative.

**Expiry is a sweep, not a timestamp.** `pointz/sweep/run` releases holds, expires lots and closes
idle balances, and every one of those writes a ledger row saying what happened. Nothing evaporates
without an audit trail.

**A coupon belongs to one customer without one discount per customer.** Commerce coupons have no
owner and no expiry, so doing this by hand means a discount per customer, and a thousand dead rows.
Pointz keeps one discount per rule, adds a single-use code each time it issues one, and checks the
owner and the expiry whenever Commerce matches the discount. Expired codes are deleted by the sweep.

**Awarding is idempotent.** An order that has earned cannot earn again, whatever fires the handler
— a completion retried after a payment hiccup, a status change that runs twice, a backfill over
the same window.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Usage](docs/usage.md)
- [Twig and events](docs/twig.md)
- [GraphQL](docs/graphql.md)
- [FAQ](docs/faq.md)
- [Troubleshooting](docs/troubleshooting.md)

## Console

```sh
php craft pointz/sweep/run                 # schedule this daily: expiry, birthdays, coupons
php craft pointz/accounts/recalculate      # rebuild every balance from the ledger
php craft pointz/grant/to-group customers 250
php craft pointz/backfill/plan --from=2026-01-01
```

## Licence

See [LICENSE.md](LICENSE.md).
