---
title: GraphQL
slug: graphql
order: 45
summary: The signed-in customer's balances, ledger, expiring value and quotes, and redeeming on their cart, for headless storefronts.
---

## What it covers

The read half of `craft.pointz`, plus the two cart actions, for a storefront that talks to Craft
through GraphQL. Available in **Lite**.

| Field | Returns | Twig equivalent |
| --- | --- | --- |
| `pointzBalance` | Spendable points | `craft.pointz.balance()` |
| `pointzCreditBalance` | Store credit | `craft.pointz.creditBalance()` |
| `pointzPendingBalance` | Points still on hold | `craft.pointz.pendingBalance()` |
| `pointzAccount` | Every balance at once | `craft.pointz.account()` |
| `pointzLedger(limit, offset, currency)` | History, newest first | `craft.pointz.ledger()` |
| `pointzExpiring(days)` | Lots about to expire, soonest first | `craft.pointz.expiring()` |
| `pointzEarnFor(purchasableId, qty)` | What a product would earn | `craft.pointz.earnFor()` |
| `pointzQuote(cartNumber, points, credit)` | What redeeming comes to | `craft.pointz.quote()` |
| `pointzWillEarn(cartNumber)` | What the cart would earn | `craft.pointz.willEarn()` |
| `pointzRedeem(...)` *(mutation)* | Applies points or credit to the cart | `pointz/cart/redeem` |
| `pointzRemoveRedemption(cartNumber)` *(mutation)* | Takes it back off | `pointz/cart/remove` |

## Turning it on

Each schema gets the fields only when you grant them, under **GraphQL → Schemas → Pointz**:

- **Query the signed-in customer's balances, ledger and quotes** adds the queries.
- **Apply and remove redemptions on the signed-in customer's own cart** adds the two mutations.

For a storefront, that's normally the public schema.

## Whose data it is

**Every field answers for the signed-in customer, and nobody else.** None of them takes a user ID
or an email. A GraphQL token is shared by every caller of its schema, and the public schema has no
token at all, so an argument naming the customer would let anyone read anyone's balance.

The customer is whoever Craft's session says is signed in. Your storefront sends the session
cookie with the request (`credentials: 'include'` with `fetch`, and the storefront's origin in
`allowedGraphqlOrigins`). A request with no session, whether from a guest or from a server that
holds only a token, gets `null` balances and empty lists. The schema decides which fields exist.
It never decides whose data comes back.

Carts follow the same rule. `cartNumber` defaults to the session's cart and lets a headless
storefront name its own. Pointz only reads or changes a cart whose customer *is* the signed-in
user. If someone else's cart number is sent, the quote is `null` and a redeem fails. This includes
a guest cart where someone typed a registered customer's email, which Commerce assigns to that
customer.

## Caching

Craft caches GraphQL results by schema, query text and variables. Nothing in that key says who
asked. Pointz therefore switches the cache off for any query that names a Pointz field, so
customer A's cached balance is never served to customer B or to a guest. The rest of your queries
are still cached. A query that mixes Pointz fields with entries isn't cached either. If the
entries are expensive, send them in a separate request.

Don't put a CDN or static cache in front of `/api` for queries that include these fields.

## Examples

```graphql
{
  pointzAccount { pointsBalance pendingPoints creditBalance }
  pointzLedger(limit: 10) { kindLabel amount balanceAfter dateCreated }
  pointzExpiring(days: 30) { remaining dateExpires }
}
```

```graphql
query Product($id: Int!) {
  pointzEarnFor(purchasableId: $id) { points }
}
```

`pointzEarnFor` also works for guests, because prices are public. A signed-in customer's own rules
and conditions are applied. It returns `null` for a purchasable that isn't live, or whose product
type the schema can't read.

```graphql
mutation Redeem($cart: String) {
  pointzRedeem(cartNumber: $cart, allPoints: true) {
    success
    message
    quote { points pointsValue maxPoints notices }
  }
}
```

`pointzRedeem` takes `points`, `credit` *(Pro)*, or `allPoints` / `allCredit` for "as much as the
cart can take". Like the action, it records an intent and spends nothing. The cart is re-clamped
every time it recalculates, and points move only when the order completes. A request the cart
can't honour comes back with `success: false` and the reason in `message`.

### CSRF

The session is the credential, so the mutations have the same cross-site-request-forgery
protection as the form action. Send them as a **POST** with the session's CSRF token in an
`X-CSRF-Token` header. `actions/users/session-info` returns the token as `csrfTokenValue`.
Without the token, the mutation errors and the cart is left as it was. The queries change nothing,
so they don't need the token.
