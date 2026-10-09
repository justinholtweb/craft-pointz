# Release Notes for Pointz

## 5.2.0 - 2026-10-09
### Added

- GraphQL, in Lite: `pointzBalance`, `pointzCreditBalance`, `pointzPendingBalance`, `pointzAccount`,
  `pointzLedger`, `pointzExpiring`, `pointzEarnFor`, `pointzQuote` and `pointzWillEarn`, plus
  `pointzRedeem` and `pointzRemoveRedemption` mutations that mirror the `pointz/cart/*` actions.
  Each set has its own schema component. Every field answers for the signed-in customer only: no
  field takes a user, and a cart is only read or changed when it belongs to the signed-in user.
  Craft's GraphQL result cache is switched off for any query that names a Pointz field, because
  that cache isn't keyed by visitor. The mutations need the session's CSRF token, sent in a POST.
- `Lifecycle::getExpiringLots()`, which lists one customer's expiring lots, each with its own
  expiry date.

### Fixed

- `craft.pointz.expiring()` could miss the customer when more than 500 accounts had value expiring
  in the window. It now filters by customer in the query.

## 5.1.1 - 2026-10-03

### Added

- `pointz/import/balances`, which loads opening balances from a CSV file: one lot per row, each with
  its own expiry date, of a new *Imported* ledger kind. Rows already imported are skipped, by their
  `reference` column or by file and line, and imported points cross no threshold rule.
- `pointz/import/coupons` *(Pro)*, which takes over coupon codes another system already issued in
  Commerce. Pointz records each code's owner and expiry, enforces them at checkout, sends its
  reminder, and deletes the old per-customer discount once its last code is used or expired. A
  discount with codes the file does not list is refused whole.
- `pointz/import/revert`, which takes back what is left of an imported balance and hands unused
  coupons back to Commerce.
- `Import` service (`Plugin::getInstance()->getImport()`) and `Coupons::clearMemo()`.
- **Pay people without an account**, an option on custom-event rules *(Pro)*. An event for an email
  address with no account creates an inactive one, as Commerce does for a guest checkout, and pays
  it — so a newsletter signup can earn a welcome coupon that is waiting when the subscriber
  registers. One reward per address; the signup bonus doesn't fire for these accounts.

### Changed

- A Formie submission from someone without an account now passes their email to `awardEvent()`, so
  a rule with *Pay people without an account* on can pay them.

## 5.1.0 - 2026-10-03

### Added

- Earning rules for things that are not an order *(Pro)*. Each pays a customer once per
  occurrence, and conditions, windows, caps and expiry apply as on any rule:
  - **An approved review**, through Stars. Options require written text, or require the reviewed
    entry to be a product the customer bought (or related to one). The reviewer is matched to a
    customer by email.
  - **A customer's birthday**, read from a Date or plain text user field and paid by
    `pointz/sweep/run`. A missed night is caught up for six days, and 29 February falls on the
    28th.
  - **Reaching a points balance.** By default the threshold is spent on the reward, so "every 500
    points becomes €5" is one rule; with spending off it is a once-ever milestone. Tiers are
    written with precedence and *stop after this one*.
  - **A custom event**, fired by handle: `formie:<form>` for a Formie submission,
    `dispatch:<list>` for a Dispatch signup from the site, or anything a module passes to
    `Rewards::awardEvent()`. A handle may end in `*`.
- **Coupons as a reward** *(Pro)*. Any rule but an order can issue a single-use Commerce coupon
  code, a percentage or a fixed amount, to one customer, with an optional expiry, an optional
  email when it is issued and an optional reminder before it expires. Pointz uses one Commerce
  discount per rule rather than one per customer. A code only discounts its owner's order and only
  until it expires, and the sweep deletes expired codes from Commerce and removes discounts nothing
  issues against any more. Changing a rule's value starts a new discount, so codes already issued
  keep their value.
- The signup bonus can award store credit or a coupon *(Pro)*.
- Two system messages, *When Pointz issues a coupon* and *When a Pointz coupon is about to expire*,
  edited under Utilities → System Messages.
- A customer's coupons on their balance page, with a **Revoke** button.
- `craft.pointz.coupons()`, and an `event` argument on `craft.pointz.activeRules()`.
- `pointz/sweep/birthdays` and `pointz/sweep/coupons`; `pointz/sweep/run` now does both.
- `Rewards::EVENT_BEFORE_REWARD`, `Coupons::EVENT_AFTER_ISSUE`, `Coupons::EVENT_BEFORE_REMIND`, and
  `Ledger::EVENT_AFTER_COMMIT`, raised after a positive movement once the account's lock is
  released, for handlers that need to move value themselves.
- An *Exchanged* ledger kind for points a threshold spends.

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
