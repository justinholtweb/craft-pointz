---
title: Usage
slug: usage
order: 30
summary: Writing earning rules, putting redemption on the front end, and the console.
---

## How a balance works

Every movement of value is a row in the ledger, and every positive movement also creates a **lot**
— an amount with its own remaining figure and its own expiry date. A balance is the sum of the
remaining figures across a customer's available lots. It is derived, never typed in.

Spending consumes lots **soonest-expiry-first** and records exactly which lots it took from. That
record is what lets a refund put value back where it came from rather than minting new value with
a new date, and it is why `pointz/accounts/recalculate` can rebuild every balance in the store
from the ledger at any time.

## Earning rules

**Pointz → Earning rules**. Rules run top to bottom and every matching rule adds to the award; a
rule with *Stop after this one* set ends the run, which is how an exclusive promotion is written.

A rule answers four questions:

- **Earned for** — a completed order, or a new customer account.
- **Applies** — once per order, or once per matching line item. A line-item rule is how "double
  points on outdoor gear" is written. *(Pro)*
- **Calculated as** — a rate per unit of value, or a fixed amount.
- **Based on** — which figure the rate is applied to. Order rules can use the item subtotal or the
  order total with shipping and tax optionally removed; line-item rules use the line's own
  subtotal or its quantity.

### Rounding

Points are whole things, so a rate that lands on 2.5 has to go somewhere. **Round down** is the
default, because nobody complains about the number they were shown. Store credit is money and
rounds to the store's currency instead.

### Limits *(Pro)*

- **Minimum award** raises anything the rule awards to at least that much — including a
  calculation that came to zero, because the rule still matched.
- **Maximum award** is a cap on the *rule* for that order. A line-item rule shares it across its
  lines in proportion, so the per-item breakdown still adds up to what was awarded.
- **Maximum per customer**, counted ever / per year / month / week / day, reads the ledger for
  what that rule has already given.

### Campaign windows *(Pro)*

A **Starts** and **Ends** pair makes the rule a campaign. Combined with a **Multiplier**, a double
points weekend is a rule you write once and never edit back:

| Field | Value |
| --- | --- |
| Rate | 1 |
| Multiplier | 2 |
| Starts | Fri 18:00 |
| Ends | Sun 23:59 |

### Conditions *(Pro)*

Three condition builders, all of which match everything when left empty:

- **Matching orders** — checked against the finished order, so order status, total paid and
  payment gateway are all meaningful here in a way they are not at cart time.
- **Matching customers** — how a rule is limited to a customer group.
- **Matching products** — only used by a line-item rule. SKU, purchasable, type or product
  category, the same four Commerce offers its own catalog pricing rules.

## Redeeming on the front end

The customer's request is stored as **intent** against the cart and re-clamped on every
recalculation. Nothing is spent until the order completes, so an abandoned cart costs the
customer nothing and a cart that sits for a week simply quotes a smaller discount.

```twig
{% set quote = craft.pointz.quote() %}

{% if quote and quote.maxPoints > 0 %}
    <form method="post">
        {{ csrfInput() }}
        {{ actionInput('pointz/cart/redeem') }}
        {{ redirectInput('shop/cart') }}

        <label>
            Spend your {{ craft.pointz.settings.pointsLabelPlural }}
            <input type="number"
                   name="points"
                   min="0"
                   max="{{ quote.maxPoints }}"
                   step="{{ craft.pointz.settings.redeemBlockSize }}"
                   value="{{ quote.points }}">
        </label>

        <p>
            You have {{ craft.pointz.formatted(quote.pointsBalance) }};
            this order can take {{ craft.pointz.format(quote.maxPoints) }}.
        </p>

        <button>Apply</button>
    </form>

    {% if quote.points > 0 %}
        <form method="post">
            {{ csrfInput() }}
            {{ actionInput('pointz/cart/remove') }}
            {{ redirectInput('shop/cart') }}
            <button>Remove {{ craft.pointz.formatMoney(quote.pointsValue) }} discount</button>
        </form>
    {% endif %}

    {% for notice in quote.notices %}
        <p class="notice">{{ notice }}</p>
    {% endfor %}
{% endif %}
```

Posting `points=max` spends everything the order can take, which is what a one-click "use my
points" button actually means.

The discount arrives as an ordinary order adjustment, so it shows up in the cart totals, in
emails and on the order without any further template work.

### Telling customers what they will earn

```twig
{# On a product page #}
{% set award = craft.pointz.earnFor(product.defaultVariant) %}
{% if award.points > 0 %}
    <p>Earn {{ craft.pointz.formatted(award.points) }} with this order.</p>
{% endif %}

{# On the cart #}
{% set award = craft.pointz.willEarn() %}
{% if award and award.points > 0 %}
    <p>You'll earn {{ craft.pointz.formatted(award.points) }}.</p>
{% endif %}
```

Both run the same rule engine that the real accrual runs at checkout, so the promise on the
product page is arithmetically the promise kept in the ledger.

A product-page preview can only answer rules that do not depend on the whole order. A rule based
on the order total genuinely cannot be answered for one product, and `award.notices` says so
rather than guessing.

## Store credit *(Pro)*

Store credit is a second balance, denominated in money rather than points, and it moves through
exactly the same ledger — so it expires, reverses and audits identically.

It arrives three ways: an earning rule set to award credit, a manual grant on the balance screen,
or a refund paid as credit from the order panel. Commerce is not told about the last one: it is a
credit note the shop chooses to issue, and the gateway refund stays a separate, deliberate
decision in Commerce's own screens.

## The control panel

- **Balances** — every customer holding value in a store, with the store's total liability in
  points and in money at the top.
- **A customer's page** — their balance, where it sits (the lots, in the order a spend will take
  them), their whole history, a box for moving the balance by hand, and a *Rebuild from the
  ledger* button, which is the support answer to "the number looks wrong".
- **Ledger** — every movement, filterable by store, kind and currency, exportable as CSV on Pro.
- **Order edit screen** — what that order earned and spent, and a link to the customer's balance.
- **Dashboard widget** *(Pro)* — the unredeemed liability, in points and in money.

## The console

```sh
# Release held value, expire what is past its date, close idle balances. Schedule this daily.
php craft pointz/sweep/run

# The pieces, if you want them on different schedules
php craft pointz/sweep/promote
php craft pointz/sweep/expire
php craft pointz/sweep/inactive
php craft pointz/sweep/expiring 30      # what is about to expire, and whose

# Rebuild every cached balance from the lots. Harmless; run it after a restore.
php craft pointz/accounts/recalculate
php craft pointz/accounts/show 42

# Hand out value
php craft pointz/grant/to-user someone@example.com 500 --note="Sorry about the outage"
php craft pointz/grant/to-group customers 250 --dryRun=1
php craft pointz/grant/revert <batch-id>

# Award orders that completed before a rule existed (Pro)
php craft pointz/backfill/plan --from=2026-01-01
php craft pointz/backfill/run --from=2026-01-01
php craft pointz/backfill/revert <batch-id>
```

Every bulk operation carries a batch ID, and `revert` takes back whatever is left of what the
batch gave. Value a customer has already spent cannot come back, and the command says how many
accounts fell short rather than pushing a balance negative to make the arithmetic tidy.
