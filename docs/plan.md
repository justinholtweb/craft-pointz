# Pointz — build plan

No YAML front matter, so `pluginsite/docs/sync` skips this file. It is the design record.

## What it is

Loyalty points **and** store credit for Craft Commerce. The Craft answer to *WooCommerce Points
and Rewards*, which is the last open slot in the Woo-rewards space for Craft: Verbb covers gift
vouchers and wishlists, Kickback covers affiliates, and nothing covers loyalty.

Package `justinholtweb/craft-pointz`, handle `pointz`, namespace `justinholtweb\pointz`.
**Lite is free; Pro is $129 with a $99/year renewal.**

## The one idea

Balances are **derived, never typed in**. Every movement is a ledger row, and every positive
movement is also a *lot* — an amount with a remaining figure and an expiry date. Redemption
consumes lots oldest-expiry-first and records which lots it took from, so a refund can put back
exactly what it took, into the same lots, with the same expiry. `pointz_accounts` is a cache of
the lot sums and can be rebuilt from the ledger at any time.

Anything that cannot survive that rule does not ship.

## Data model

- `pointz_accounts` — (userId, storeId) unique. Cached `pointsBalance`, `pendingPoints`,
  `creditBalance`, `pendingCredit`, `lifetimePoints`, `dateLastActivity`. Rebuildable.
- `pointz_transactions` — the append-only ledger. Signed `amount`, `currency` (`points|credit`),
  `kind` (earn/redeem/expire/adjust/reverse/refund), `status`, `orderId`, `ruleId`, `batchId`,
  `note`, `balanceAfter`.
- `pointz_lots` — one per positive transaction. `amount`, `remaining`, `status`
  (`pending|available|expired|revoked`), `dateAvailable`, `dateExpires`.
- `pointz_lot_uses` — which lot each negative transaction took from, and how much. This is what
  makes reversal exact.
- `pointz_rules` — earning rules per store: event, scope (order or line item), calculation,
  conditions, caps, campaign window, expiry.
- `pointz_cart_redemptions` — the customer's *intent* for a cart (orderId PK). Points and credit
  are only spent at completion; this row is what the adjuster reads.

## Integration points

| Moment | Hook | What happens |
| --- | --- | --- |
| Cart recalculates | `OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS` | The Redemption adjuster turns the cart's intent into (clamped) negative adjustments. Nothing is spent. |
| Order completes | `Order::EVENT_BEFORE_COMPLETE_ORDER` | Debit the ledger for exactly the adjustments on the order, under a per-account mutex. |
| Order completes | `Order::EVENT_AFTER_COMPLETE_ORDER` | Run earning rules; write pending or available lots. |
| Status changes | `OrderHistories::EVENT_ORDER_STATUS_CHANGE` | Promote pending lots when the order reaches the configured status. |
| Refund saved | `Transactions::EVENT_AFTER_SAVE_TRANSACTION` | Reverse earned points pro rata; optionally return redeemed points to their original lots. |
| User signs up | `Elements::EVENT_AFTER_SAVE_ELEMENT` (new User) | Signup bonus rule. |

## Editions

**Lite** — one order-level earning rule per store, signup bonus, points redemption (rate, minimum,
block size, cap), balances and ledger, manual adjustments, pending/hold and refund reversal, one
global expiry policy, order and user panels, Twig API, console.

**Pro** — many rules including per-line-item rules with purchasable/category conditions, order and
user conditions, campaign windows and multipliers, per-rule and per-period caps, **store credit**
(grant, redeem, refund-to-credit), per-rule expiry plus inactivity expiry plus expiry-warning
notices, backfill with dry run and undo, ledger CSV, dashboard widget.

Refund reversal and pending holds are deliberately **not** Pro: a free edition that over-awards is
a bug, not an upsell.

## Phases

1. Foundation — plugin class, tables, records, models, `Accounts`/`Ledger` services, settings.
2. Earning — rules service, rule evaluation, order completion, signup.
3. Redemption — cart intent, adjuster, debit at completion, Twig and site actions.
4. Lifecycle — promotion, expiry, refund reversal, console commands, queue jobs.
5. Store credit — issue, redeem, refund-to-credit.
6. Control panel — balances, ledger, rules, adjustments, order/user panels, widget.
7. Backfill, docs, checks.
