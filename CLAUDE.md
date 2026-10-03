# Pointz — Craft CMS 5 Plugin

## Project Overview

Pointz gives Craft Commerce loyalty points and store credit — the Craft answer to *WooCommerce
Points and Rewards*, which was the last open slot in that space (Verbb covers gift vouchers and
wishlists, Kickback covers affiliates, nothing covered loyalty). Distributed as
`justinholtweb/craft-pointz`. **Lite is free; Pro is $129 with a $99/year renewal.**

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, and the only JS is a few `Craft.sendActionRequest` calls

## Architecture

### Namespace & package

- Namespace: `justinholtweb\pointz`
- Package: `justinholtweb/craft-pointz`
- Handle: `pointz`

### The one idea

**Balances are derived, never typed in.** Every movement is a row in `pointz_transactions`, and
every positive movement also creates a **lot** — an amount with its own `remaining` figure and its
own `dateExpires`. A balance is the sum of the remaining figures across a customer's available
lots. `pointz_accounts` caches those sums and `pointz/accounts/recalculate` rebuilds the whole
table from the lots at any time.

Spending consumes lots soonest-expiry-first and writes `pointz_lot_uses` rows saying which lots it
took from and how much. That table is what makes a refund exact: value goes back into the lot it
came from, with its original expiry, rather than being minted fresh.

Anything that cannot survive that rule does not ship.

### The integration points

| Moment | Hook | What happens |
| --- | --- | --- |
| Cart recalculates | `OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS` | The Redemption adjuster turns the cart's intent into clamped negative adjustments. Nothing is spent. |
| Order completes | `Order::EVENT_BEFORE_COMPLETE_ORDER` | Debit for exactly what the adjustments say, under a per-account mutex. *Before*, so a store that has chosen to fail on a shortfall can still stop the order. |
| Order completes | `Order::EVENT_AFTER_COMPLETE_ORDER` | Run the earning rules. *After*, and wrapped in a `try`, because earning must never be able to fail a checkout. |
| Order paid | `Order::EVENT_AFTER_ORDER_PAID` | Award if `awardOn` is `paid`; release the order's held lots. |
| Status changes | `OrderHistories::EVENT_ORDER_STATUS_CHANGE` | Award if `awardOn` is `status` and the handle matches. |
| Refund saved | `Transactions::EVENT_AFTER_SAVE_TRANSACTION` | Reverse pro rata; optionally return what the order spent. |
| New user | `User::EVENT_AFTER_PROPAGATE` with `isNew` | The signup bonus. |
| Points arrive | `Ledger::EVENT_AFTER_COMMIT` (and `Lifecycle::_release()`) | Evaluate threshold rules. *After the commit*, because a threshold spends and the ledger can't be re-entered from inside its own lock. |
| Discount matches | `Discounts::EVENT_DISCOUNT_MATCHES_ORDER` | A Pointz coupon code only matches its owner's order, and only while live. |
| Order completes | `Order::EVENT_AFTER_COMPLETE_ORDER` | Also marks the order's Pointz coupon used. |
| Review saved | `Element::EVENT_AFTER_SAVE` on Stars' `Review`, by class name | Review rules, once approved. |
| Form submitted | Formie `Submissions` `afterSubmission`, by class name | Custom event `formie:<formHandle>`. |
| List joined | Dispatch `SubscriptionRecord` after insert, by class name | Custom event `dispatch:<listHandle>`, site requests only, so an import doesn't pay a whole list. |

The adjuster is registered **last** on purpose: it has to see shipping, discount and tax before it
can cap a redemption against a real total. `Order::recalculate()` clears every adjustment before
the run and merges them in progressively, so the adjuster can read `getTotal()` and never sees its
own previous output — which is what stops the discount compounding.

### Data model

Seven tables, all hard deletes:

- `pointz_accounts` — (storeId, userId) unique. A cache; rebuildable.
- `pointz_transactions` — the append-only ledger. Signed `amount`, `balanceAfter` for support
  questions, `status` (pending/posted/reversed), `orderId`, `ruleId`, `authorId`, `reversesId`,
  `batchId`.
- `pointz_lots` — one per positive transaction. `remaining` is the number that matters.
- `pointz_lot_uses` — what a spend took, from where, and how much has been given back.
- `pointz_rules` — earning rules, ordered by `sortOrder`, which *is* precedence.
- `pointz_cart_redemptions` — the customer's intent for a cart, keyed on `orderId`.
- `pointz_coupons` — every code Pointz issued: owner, rule, Commerce `discountId`/`couponId`,
  `type` and `amount` as issued, `status` (active/used/expired/revoked), `dateExpires`,
  `dateRemind`. The code is copied here because Commerce's row is deleted when a code expires.

Rules live in the **database, not project config**, the same call Commerce makes for its own
discounts and shipping rules: they are commercial configuration a merchandiser changes on a Friday
afternoon, not schema that has to move between environments in lockstep.

### The invariant

`services\Ledger` is the **only** writer. `credit()`, `debit()`, `restore()` and `revoke()` each
take a `pointz:account:<storeId>:<userId>` mutex and run inside a database transaction, then
refresh the account cache and stamp `balanceAfter`. Nothing else may write to
`pointz_accounts`, and if a number there is ever wrong it is because something skipped the ledger.

### Rewards that are not an order

`services\Rewards` runs signup, review, birthday, threshold and custom-event rules (`Rule::proEvents()`
are Pro, gated in `Rule::validateEdition()` and again in `Rules::getActiveRules()`). Everything pays
through `Rewards::grant()`, which dedupes on (rule, user, **reference**): `stars:review:<id>`,
`birthday:<year>`, the caller's own reference, or none, which means once ever. Points and credit
go through the ledger with `reference` on the transaction; a coupon goes through
`Coupons::issue()`.

A threshold rule with *spend* on debits the threshold (kind `reward`, "Exchanged") and pays
against that debit; if the reward throws, the debit is `restore()`d. It runs under its own
`pointz:rewards:<store>:<user>` mutex so two arrivals can't both spend the same points. Re-entry
finds the lock taken and returns.

### Coupons: one discount per rule

Commerce coupons have no owner or expiry, so the naive build is one discount per customer. Pointz
keeps **one discount per rule** (`couponDiscountId`, created on rule save) and adds a `maxUses = 1`
code per issuance. `Coupons::enforceOwnership()` makes `discountMatchesOrder` false unless the
order's customer owns the code and it's live. The discount is **replaced, never edited**, when the
rule's value changes, so issued codes keep their value. `cleanUpDiscounts()` deletes a replaced or
orphaned discount once no live code is left on it. `couponDiscountId` is written only by
`ensureDiscount()`, never by `saveRule()`.

### Imports are lots and adopted codes

`services\Import` loads another system's balances as one `import`-kind credit per row (a lot each,
with its own expiry), never as replayed history; imported points skip the threshold listener. Its
coupon half *adopts* existing Commerce codes into `pointz_coupons` with `ruleId` null, which makes
their discount managed — so `enforceOwnership()` applies and `cleanUpDiscounts()` will delete it.
That is why a discount with any code the file doesn't list is refused whole. Re-runs dedupe on
the transaction `reference` (`import:<ref>` or `import:<filehash>:<line>`), ignoring batches that
have been reverted.

### Accounts for email addresses

A custom-event rule with `createAccount` on lets `awardEvent()` take an email nobody has an
account for: `Rewards::_createAccountFor()` calls Craft's `ensureUserByEmail()` (an inactive user,
which Craft's registration later reuses) only if a matching active rule asks. The signup hook
checks `Rewards::isCreatingAccount()` and stands aside, so a subscriber isn't paid a signup bonus.

### Install seeds a disabled rule

`Install::seedDefaultRules()` creates one rule per store with `enabled = false`. Installing a
loyalty plugin must never start paying out on a live store.

### Redemption is intent, not a debit

The customer's request lives in `pointz_cart_redemptions` and is re-clamped on every
recalculation. The **request** is stored rather than the clamp, so a customer who asks for 500 on
a small cart and then adds an item gets all 500.

Completion reads the *adjustments*, not the intent, because the adjustments are what the customer
is actually being charged. The points count travels in `sourceSnapshot['points']` so a rate change
between cart and checkout cannot alter what is spent.

### Whose balance a cart spends

**Never the bare `getCustomerId()`.** Commerce makes the owner of an email the customer of a guest
cart that types it in (`commerce/cart/update-cart` → `ensureUserByEmail()` → `setCustomer()`), so
the cart's customer is not proof of anything. `Redemption::spenderId()` is the one answer: the
customer, only if they are the signed-in user, or if they applied the redemption themselves (the
intent row's `userId`) — which is what a recalculation or completion in a queue job or gateway
webhook, with nobody signed in, goes on. `quote()`, the adjuster and `commitOrder()` all use it;
`CartController::actionRedeem` refuses anyone but the signed-in customer. Fixed in 5.0.1.

### Honest about shortfalls

Clamp mode spends what is there, notes the shortfall on the order and warns to the log. Throw mode
exists and is documented as a trade rather than a safety feature: `markAsComplete()` has no
`try`/`finally` around its event, so a handler that throws leaves the completion mutex held.

## Traps found while building this

- **A rule that computes zero still matched.** Dropping zero-value award lines before the caps run
  makes **Minimum award** dead code — the floor exists precisely to rescue a calculation that came
  to nothing. Lines are produced whenever the rule matches, and zeroes that no floor rescues are
  dropped *after* the caps. (Caught by the checks; it was the one failure in the first full run.)
- **Only the item-subtotal basis needs the redemption subtracted.** The total-based bases already
  have Pointz's own negative adjustments in them, so subtracting again double-counts.
  `Earning::redeemedValue()` returns zero for those on purpose.
- **`ORDER BY dateExpires` puts NULLs first in MySQL and last in Postgres.** The FIFO read needs
  `CASE WHEN [[dateExpires]] IS NULL THEN 1 ELSE 0 END` in front of it, or a never-expiring lot is
  spent before the dated ones and the dated ones then expire unspent.
- **Craft's console `Request` has no `getBodyParam()`**, so a CP template extending
  `_layouts/cp` cannot be rendered from a console script at all — the layout itself calls it.
  Templates that do not extend it (`settings.twig`, `_widget.twig`) render fine. Verify full CP
  screens over HTTP with a cookie jar instead.
- **The plugin edition lives in project config**, and this harness has no `projectconfig` table,
  so `ProjectConfig::flush()` throws. Editing `config/project/project.yaml` and running `craft up`
  is the way to test the Pro branches over HTTP.
- **`Db::update()` returns the affected row count**, which is not the same as the number of rows
  you asked it to touch when a concurrent sweep got there first. The promotion sweep reads the
  transaction ids *before* moving the lots for that reason.

- **Commerce only discounts promotable purchasables**, and `Purchasable::$promotable` defaults to
  false. A coupon that "matches but discounts nothing" is almost always this. The check fixtures
  pass `makeProduct(..., promotable: true)` for coupon scenarios.
- **Re-saving a Commerce discount rewrites its whole coupon list** (`saveDiscountCoupons()` deletes
  any code not on the model). Pointz never re-saves its discounts. Codes go in through
  `Coupons::saveCoupon()` one at a time.
- **A stale rule model will put back a deleted `couponDiscountId`.** `onlyRules()` re-saves models
  loaded earlier in the run. Writing that column from `saveRule()` hit the FK once a cleanup had
  deleted the discount, which is why only `ensureDiscount()` writes it, reading the current value
  from the database.
- **Yii mutexes aren't re-entrant**: acquiring a lock you already hold returns false. That's why
  thresholds hang off `EVENT_AFTER_COMMIT`. `EVENT_AFTER_TRANSACTION` fires inside the account lock.
- **Stars stores a reviewer email and an entry, not a user and a product.** The customer is found
  by email, and "bought it" means a purchased product (or its variant) that is the entry or is
  related to it in either direction.
- **Dispatch has no "subscribed" event**, and its CSV import goes through the same `subscribe()`.
  The hook listens to `SubscriptionRecord` inserts and ignores console, queue and CP requests.
  The check stands in a site request by swapping in a `craft\web\Request` with
  `setIsConsoleRequest(false)`.

See also `[[craft-plugin-gotchas]]` and `[[craft-commerce-shipping-gotchas]]` in the shared memory
for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-pointz/tests/integration/checks.php          # 103 checks
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-pointz/tests/integration/security.php        # 11, a guest vs the signed-in customer over HTTP
docker exec -w /sites/craft-pointz ddev-phpstan-runner-web \
  bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
docker exec -w /var/www/html ddev-plugin-testing-web \
  bash -c 'find /var/www/craft-pointz/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Use `docker exec`, not `ddev exec` — `ddev exec` re-checks the project's health and this harness
takes longer to pass that check than ddev waits.

The checks are idempotent and self-cleaning: every customer, rule, product and order they create
they delete again in a `finally`, and deleting the customer cascades their ledger with them. The
edition and the settings are changed **in memory** (`$plugin->edition = …`, mutating the memoized
settings model) rather than saved, because project config is contended in this harness.

`onlyRules([...])` is the fixture helper that matters: rules stack by design, so every scenario
has to state which of the run's rules are switched on or the previous scenario's rule quietly adds
to this one's award.

## Coding conventions

- `Craft::t('pointz', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Nothing in the Twig API or a preview may move a balance
