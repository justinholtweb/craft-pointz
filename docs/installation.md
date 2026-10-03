---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and switching on your first earning rule.
---

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+

## Install

```sh
composer require justinholtweb/craft-pointz
php craft plugin/install pointz
```

## Nothing happens until you say so

Pointz installs one **disabled** earning rule per store, called *Points on every order*. Adding a
loyalty plugin must never start handing out points to a live store before anyone has looked at
the numbers, so nothing is awarded until you open **Pointz → Earning rules**, set a rate and
switch it on.

## The five-minute setup

1. **Pointz → Settings → Redeeming**: set *Points per unit of currency*. The default of 100 means
   100 points are worth 1.00 in your store's currency. This single number is the exchange rate for
   everything else, so decide it before anyone earns anything — changing it later re-prices every
   balance in the store.
2. **Pointz → Earning rules**: open the seeded rule, set the rate (1 point per unit spent is the
   usual starting place), and switch it on.
3. Put a balance somewhere the customer can see it:

   ```twig
   {% if currentUser %}
       <p>You have {{ craft.pointz.formatted(craft.pointz.balance()) }}.</p>
   {% endif %}
   ```

4. Add the redemption form to your cart or checkout template — see
   [Usage](usage).

5. Schedule the sweep daily if you're going to expire anything, pay birthday rewards or issue
   coupons that expire:

   ```sh
   php craft pointz/sweep/run
   ```

   Nothing expires because a date passed. It expires because that ran. The same goes for
   birthdays being paid and coupon reminders being sent.

## Permissions

Two permissions, both under **Pointz** in the user group settings:

- **View balances and the ledger** — the Pointz section, the order panel, and every read-only
  screen.
- **Grant and deduct value** — nested under the first one. Moving a balance by hand, issuing store
  credit against an order, and rebuilding a balance from the ledger.

**Manage earning rules** is separate: writing a rule that awards double points is a commercial
decision, not a support one.

## Moving from WooCommerce or another loyalty plugin

A WooCommerce points log has no lots in it, only a running total, so Pointz imports what each
customer holds today rather than the history:

1. Export each customer's current balance, one row per expiry date if the old system has them.
2. Run `pointz/import/balances` on it, with `--dry-run` first.
3. If the old system issued coupons, run `pointz/import/coupons` too, and Pointz takes over their
   ownership, reminders and clean-up. *(Pro)*
4. Leave the history behind. It stays readable in the old system, and Pointz's ledger starts on a
   date you can point to.

Each import is one batch, so a mistake in the export is undone with `pointz/import/revert`. See
[Moving from another loyalty system](usage.md#moving-from-another-loyalty-system) for the columns.
