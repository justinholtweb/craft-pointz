# Release Notes for Pointz

## 5.0.1 - 2026-10-03

### Security

- A guest could spend a registered customer's points and store credit. Commerce makes the owner of
  an email the customer of a guest cart that types it in, and redemption only looked at the cart's
  customer — so a guest who knew a customer's email could see their balances in the quote and spend
  them at checkout. Redemption now needs that customer to be signed in, records who applied it, and
  only ever spends from the account of the person who asked. A quote shows anyone else zeroes.
- Points or credit already applied to carts are cleared by the update, because there is no telling
  who applied them; customers apply them again.

### Fixed

- The Liability dashboard widget's settings were a fatal error on any install with more than one
  store: they called a `View` method Craft doesn't have.
- `pointz/accounts/recalculate` printed blank progress lines — a curly ellipsis straight after
  `$done` made PHP read it as part of the variable's name.

### Added

- `Redemption::spenderId()`, and an optional `$userId` on `setIntent()`.
- `tests/integration/security.php`: 11 checks, over HTTP as a guest and as the signed-in customer.
- PHPStan and ECS configuration, with `composer phpstan`, `check-cs` and `fix-cs`.

## 5.0.0

Initial release.

### Added

- Loyalty points and store credit for Craft Commerce, built on a lot-based, append-only ledger:
  every positive movement creates a parcel of value with its own remaining figure and expiry date,
  and a balance is the sum of those parcels rather than a number anything writes directly.
- Spending consumes lots soonest-expiry-first and records which lots it took from, so a refund
  returns value to the lot it came from with its original expiry rather than minting new value.
- `pointz/accounts/recalculate`, which rebuilds every cached balance in the install from the
  ledger — the recovery tool, and the guarantee that the cache is only ever a cache.
- Earning rules per store: a rate per unit of value or a fixed amount, over the item subtotal or
  the order total with shipping and tax optionally removed, with configurable rounding.
- Per-line-item rules matched by SKU, purchasable, type or product category, so "double points on
  outdoor gear" is one rule. *(Pro)*
- Order and customer condition builders on every rule, campaign windows with a multiplier, a
  minimum and maximum award, and a per-customer cap counted ever / yearly / monthly / weekly /
  daily. *(Pro)*
- A signup bonus rule, guarded by the ledger's own history so re-saving a user cannot pay twice.
- Redemption at checkout as an order adjustment, registered after shipping, discounts and tax so
  its cap is measured against a real total. The customer's request is stored as intent and
  re-clamped on every recalculation; nothing is spent until the order completes.
- The points count travels in the adjustment's `sourceSnapshot`, so a rate change between cart and
  checkout cannot alter what is spent.
- A conversion rate, a minimum redemption, a block size, a percentage cap and a choice of which
  figure the cap is measured against — including whether points may pay for shipping and tax.
- Store credit as a second balance in the store's own currency, sharing the ledger, the expiry
  machinery and the refund behaviour. Granted by hand, awarded by a rule, or issued against an
  order as a credit note. *(Pro)*
- Holds: new value can stay pending for a set number of days, or until the order is paid or
  reaches a chosen status.
- Expiry driven by `pointz/sweep/run` rather than by a passing timestamp, with a site-wide
  lifetime, per-rule overrides *(Pro)*, inactivity expiry *(Pro)* and an expiry-warning query
  *(Pro)*.
- Refund reversal on a successful refund transaction — proportional, full or off — measured
  cumulatively, plus optional return of the points the order spent.
- Balances, a per-customer page showing the lots in the order a spend will take them, a filterable
  ledger, CSV export *(Pro)*, an order-edit panel and a liability dashboard widget *(Pro)*.
- Manual grants and deductions, batch grants with a batch ID, and `revert` for any batch.
- Backfill with a dry run, a batch ID and an undo, for orders that completed before a rule
  existed. *(Pro)*
- `craft.pointz` — balances, ledger, quotes, earn previews, conversions and the store's own words
  for a quantity. Nothing on it writes.
- `Earning::EVENT_BEFORE_AWARD` for logic the rule builder cannot express, and
  `Ledger::EVENT_AFTER_TRANSACTION` for reacting to a movement.
- Console commands for sweeping, recalculating, granting and backfilling.
