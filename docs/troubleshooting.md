---
title: Troubleshooting
slug: troubleshooting
order: 60
summary: Nothing was awarded, the discount will not apply, balances look wrong.
---

## Nothing was awarded

In order of likelihood:

1. **The rule is switched off.** Pointz installs its seeded rule disabled on purpose.
2. **Earning is switched off** — Settings → Earning → *Award points on completed orders*.
3. **Award when** is set to *paid* or *a status*, and the order has not got there yet. Points are
   waiting, not lost.
4. **The order has already earned.** Awarding is idempotent, keyed on the order — a status handler
   that fires twice cannot pay twice. Look at the order panel.
5. **A condition does not match.** Order conditions run against the *finished* order, so a
   condition on order status matches the status at completion, not the one you set afterwards.
6. **The rounding ate it.** A rate of 0.01 on a 25.00 order is 0.25 points, and *round down* makes
   that zero. Set a **Minimum award**, which applies even when the calculation came to nothing.
7. **The customer is a guest and guests are switched off.**

`php craft pointz/backfill/plan --from=<date>` will tell you what the current rules would award
those orders, without writing anything — the fastest way to see whether the rules are the problem
or the plumbing is.

## The discount will not apply

- **The customer is not signed in.** A guest cart has no balance to spend.
- **The order is too small.** Points are capped by the order and by **Maximum share of an order**;
  `craft.pointz.quote().notices` says which one bit.
- **A block size or a minimum is rounding the request to zero.** Both are in Settings → Redeeming.
- **The intent was set on a different cart.** Redemption intent belongs to an order, so a cart
  that was merged or replaced does not carry it over.

## A balance looks wrong

Open **Pointz → Balances → the customer** and press **Rebuild from the ledger**, or run
`php craft pointz/accounts/recalculate` for the whole store. Balances are a cache of the lots, and
the lots are the truth. If the number changes, something wrote around the ledger; if it does not,
the history on that page will show you which movement you did not expect.

## Points expired that should not have

Expiry only runs from `pointz/sweep/run`. If value disappeared, the ledger has an **Expired** row
for it saying exactly how much and when. Two things to check:

- **Expires after** is site-wide; an earning rule may set a shorter one for its own value.
- **Expire an idle balance after** takes the *whole* balance when a customer has neither earned
  nor spent for that long, regardless of when each lot was earned.

## A refund did not take the points back

- **When an order is refunded** may be set to *Leave earned value alone*.
- The reversal is driven by a **successful refund transaction**, not by an order status. Commerce
  has no "order refunded" event, and a status named "Refunded" is a convention a shop may or may
  not follow — the money moving is the only fact. Marking an order refunded by hand changes
  nothing.
- The points may already be spent. Check the ledger for a **Reversed** row and the logs for the
  shortfall.

## Two customers, one balance

Commerce attaches a customer to every order, including guest checkouts, and a guest who later
registers with the same email is the same user. A guest who registers with a *different* email is
a different customer with a different balance, and there is no way for Pointz to know they are the
same person.

## Store credit is missing from the control panel

Store credit is Pro. On Lite the credit fields are hidden and the service refuses to move credit
at all rather than silently doing nothing.

## Errors in the logs

Pointz logs to the `pointz` category. Earning failures are logged and swallowed on purpose — a
loyalty scheme is never worth a failed checkout — so `storage/logs` is the place to look when an
order completed and the panel is empty.

Switch on **Log every movement** while you are setting up to get a line per movement, and switch
it off again afterwards.
