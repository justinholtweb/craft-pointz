---
title: FAQ
slug: faq
order: 50
summary: Common questions about loyalty points and store credit in Craft Commerce.
---

### Does it work with Craft Commerce discounts, sales and gift vouchers?

Yes. The redemption is an ordinary order adjustment and Pointz's adjuster is registered **last**,
so shipping, discounts and tax are already on the order before points are applied — and the cap is
measured against a real total rather than an optimistic one.

### Can a customer pay for a whole order in points?

Yes, unless you set **Maximum share of an order**. Points are also capped by whichever figure you
choose under **Redeemable against**, which is how you stop points paying for shipping and tax.

### What happens if the balance changes between the cart and the checkout?

The cart re-quotes on every recalculation, so this only bites when the balance moves in between —
a second tab, a manual deduction, an expiry sweep. By default the customer keeps the discount they
were quoted, the ledger records what was actually there, and a notice on the order makes the
difference visible. You can choose to fail the completion instead.

### Does a refund take the points back?

By default, the refunded share of them. Reversal is cumulative, so two 20% refunds take 40% in
total. Points a customer has already spent cannot come back — Pointz reports the shortfall rather
than pushing a balance negative.

The points an order *spent* are handed back in full by default, because a customer who paid partly
in points and got their money back is owed the points.

### Do points expire?

Only if you switch it on, and only when `pointz/sweep/run` runs. Nothing expires because a date
passed; it expires because the sweep noticed. That is deliberate — a loyalty balance that
evaporates without an audit trail is a support ticket waiting to happen.

### What happens to points earned on an order that is then refunded, if the customer already spent them?

The lot they came from is emptied as far as it goes, and the rest is reported as a shortfall. The
alternative — a negative balance — is worse: it silently eats the next thing the customer earns.

### Can I change the exchange rate later?

You can, and it re-prices every existing balance at once, because a balance is stored in points
and converted at spend time. Decide the rate before anyone earns anything.

### Does it support several stores?

Yes. Balances, ledger rows and earning rules are all per store, and a customer holds a separate
balance in each. The exchange rate, the expiry policy and the refund behaviour are one set of
numbers for the install.

### Do guests earn?

Commerce gives every order a customer account, guest checkouts included, so a guest's points are
waiting for them if they activate it. **Guest checkouts earn** turns that off.

### Where do I put the balance on the front end?

Anywhere. `craft.pointz.balance()` and `craft.pointz.ledger()` are all you need for an account
page; see [Twig and events](twig).

### How do I give a customer points by hand?

**Pointz → Balances → the customer**, or `pointz/grant/to-user`. Both go through the ledger, so
what you grant expires and reverses exactly like something earned.

### Can I award points for a review, a birthday, a newsletter signup or a referral?

Reviews, birthdays and signups, yes, in Pro: they're triggers on an earning rule, alongside
reaching a points balance and a custom event you can fire from a Formie form, a Dispatch list or
your own code. See [Rewards that are not an order](usage.md#rewards-that-are-not-an-order-pro).

Referrals aren't built in yet. A referral programme needs its own codes, its own record of who
referred whom, and protection against people referring themselves, and it is planned as a feature
of its own. Until then, a custom event with a reference does the paying part:

```php
Plugin::getInstance()->getRewards()->awardEvent($referrer, 'referral', reference: 'referred:' . $newCustomer->id);
```

### Can it give out coupons instead of points?

Yes, in Pro. Any rule that isn't an order can award a single-use coupon, tied to one customer and
optionally expiring, with a reminder email before it does. It uses one Commerce discount per rule
rather than one per customer. See [Coupons as a reward](usage.md#coupons-as-a-reward-pro).

### What is the difference between points and store credit?

Points are a loyalty currency with an exchange rate you set; store credit is money, denominated in
the store's own currency. They share the ledger, the expiry machinery and the refund behaviour,
and differ in what a customer sees and in how the caps apply — the percentage cap is for points,
because credit is the customer's own money.

### Why is my balance not what I expect?

Open the customer's page and press **Rebuild from the ledger**. A balance is a cache of the lots;
if the cache is ever wrong, the lots are right and the rebuild fixes it. If it stays wrong after a
rebuild, the ledger on that page will show you exactly which movement was unexpected.

### Does it need a queue runner?

No. Every movement is written synchronously in the request that caused it. The only scheduled work
is `pointz/sweep/run`, which is a cron job rather than a queue job so a stalled queue cannot
silently stop expiry.
