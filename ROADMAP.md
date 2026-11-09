# Roadmap

Planned work after the seed, with an hour estimate per item. The total is 26 hours.

## 1. Sign-in for changes, 3 hours

- What: API tokens with Laravel Sanctum, with 2 roles: staff can add and remove products and rules, and the shop front can only quote and place orders.
- Why: today, anyone who can reach the API can add a rule that gives everything away.

## 2. Bundles with quantities, 2 hours

- What: let a bundle list a quantity per item, such as 1 teapot and 2 mugs for $80.
- Why: today, a bundle takes exactly 1 of each item, and many real offers need 2 or more of 1 item.

## 3. Move a cut cart-wide discount to other lines, 1.5 hours

- What: when a floor cuts a line's share of a bundle or order discount, hand the cut part to the other lines that still have room.
- Why: today, that part is lost. A $10 coupon can therefore give less than $10 while other lines could have taken the rest.

## 4. Rule changes with history, 3 hours

- What: `PATCH /api/rules/{id}` that stores each change as a new version, with every quote and order recording the version of each rule it used.
- Why: a customer may ask about an order weeks later. The answer must come from the rules as they were then, not as they are now.

## 5. Best price among exclusive rules, 2 hours

- What: an option to try every eligible exclusive rule on its own and keep the 1 that gives the lowest total, instead of the 1 with the highest priority.
- Why: shops often run 2 offers that cannot combine and promise the customer the better 1.

## 6. Per-customer coupon limits, 2 hours

- What: a limit of uses per customer id beside the total limit, checked under the same lock.
- Why: a welcome coupon is meant to be used once by each customer, and a total limit cannot express that.

## 7. Taxes, 3 hours

- What: tax classes per product, rates per region, prices entered with or without tax, with the tax worked out after the discounts and shown per line.
- Why: a quote without tax is not the amount the customer pays.

## 8. More than 1 currency, 2.5 hours

- What: a currency per price list, with rules that state their currency, and amounts kept in the minor unit of each currency.
- Why: a shop that sells in Canada and the United States needs separate prices, not a converted total.

## 9. A race test for coupon limits, 1.5 hours

- What: a test that fires 20 orders at once at a coupon with 3 uses on PostgreSQL and requires exactly 3 orders and 17 refusals.
- Why: the lock was checked by hand with 20 parallel requests (3 orders, 17 answers of 409). A test keeps that check in the suite.

## 10. OpenAPI description, 1.5 hours

- What: an OpenAPI 3.1 file for every endpoint, with a test that checks the real answers against it.
- Why: a shop front written in another language needs the request and answer shapes without reading PHP.

## 11. Windows and macOS in CI, 2 hours

- What: add Windows and macOS to the SQLite test-job in CI, then fix any fault that shows only there.
- Why: today, CI runs on Linux only. A fault that shows only on Windows or macOS (such as a path separator or a missing PHP extension) is therefore not caught.

## 12. Release v0.1.0, 2 hours

- What: a change log, a tag, a Docker Compose file that starts the API with PostgreSQL and the sample shop.
- Why: the set-up takes PHP, Composer, a database today, and 1 command lowers the bar for trying it.

## Total

26 hours.
