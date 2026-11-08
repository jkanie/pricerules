# pricerules

A pricing and discount rule engine with an HTTP API, built on Laravel. You store products and rules, send a cart, and get back the price of every line plus the reason each rule applied or was skipped.

## What it does

- Keeps products and pricing rules in the database. All money is in whole cents.
- Supports 6 rule types:
  - `percent_off`: a share of each matching line, in basis points (1,000 = 10%).
  - `amount_off`: a fixed amount off each matching unit, or off each matching line.
  - `buy_x_get_y`: for every complete group of buy + get units of 1 item, the get units are free (or a share off, in basis points).
  - `tiered`: a share off a line whose quantity reaches a tier. The best tier reached counts.
  - `bundle`: 1 of each listed item together costs a set price. A cart with 2 of each holds 2 bundles.
  - `order_amount_off`: a fixed amount off the whole cart once the cart reaches a minimum.
- Lets each rule target SKUs, categories or both (empty means every item). A bundle lists its own SKUs, and an order discount covers the whole cart.
- Limits a rule by date window, customer group or coupon code. A coupon code can carry a usage limit.
- Runs rules by priority. A rule is stacking (the default) or exclusive.
- Never takes a line below its floor: the product's cost times the quantity when a cost is stored, otherwise 0.
- Explains every rule on every quote: applied, with the amount, or skipped, with the reason.
- Prices a cart with no side effects (`POST /api/quotes`), or prices it and stores it as an order, which spends 1 use of each coupon that applied (`POST /api/orders`).
- Locks the coupon rules while an order is priced. 2 orders can therefore never both take the last use of a coupon.

## Who it is for

Shops and agencies that need discount rules they can read, test and explain to a customer, outside any 1 e-commerce platform. A typical case is a buy-2-get-1 offer, a trade discount, a coupon in the same cart. The customer then asks why the total came out as it did, and the quote answers.

## Built with

- PHP 8.4.27. `composer.json` requires PHP 8.4.27 or later in 8.4, or 8.5.12 or later. Those are the first releases with the fixes for CVE-2026-109618 and CVE-2026-109619. The locked Symfony 8 packages need PHP 8.4 in any case.
- Laravel 13.40.0, started from the laravel/laravel 13.11.0 skeleton, run with Composer 2.8.12.
- Tests: PHPUnit 12.5.43 through `php artisan test`. Style: Laravel Pint 1.32.1.
- Databases: SQLite 3.45.1 (the default, and the test database) and PostgreSQL 16.15. The full test suite passes on both.

## Set-up

You need PHP 8.4.27 or later (or 8.5.12 or later) with the `pdo_sqlite` extension (or `pdo_pgsql` for PostgreSQL) and Composer.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

The seed step adds a small sample shop: 4 products and 5 rules. The API then answers at `http://localhost:8000/api`.

For PostgreSQL, set `DB_CONNECTION=pgsql` and the `DB_` lines in `.env` before you run `php artisan migrate --seed`.

## Tests

```sh
php artisan test
vendor/bin/pint --test
```

To run the same tests on PostgreSQL, point them at an empty database:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=pricerules_test \
  DB_USERNAME=pricerules DB_PASSWORD=secret php artisan test
```

26 tests with 12,450 assertions:

- The oracle test prices 500 seeded random carts against seeded random rule sets 2 ways: with the engine, and with a slow reference pricer in `tests/Support/ReferencePricer.php`. The reference is written from the rules in this README, without the engine's code. It counts free units 1 at a time, builds bundles 1 set at a time and hands out leftover cents 1 at a time. Every total, every discount on every line and every applied rule must match.
- The same test checks the rules every quote must keep: no line below its floor or below 0, lines that add up to the total, a reason for every rule, an exclusive rule that applies alone.
- Another test shuffles the rules of 100 scenarios and requires the same quote, which proves that the order the rules come in does not matter.
- A database test stores 60 of the scenarios, prices them through `POST /api/quotes`, and compares the answer with the reference.
- Unit tests pin each rule type, the rounding, the floors, the date window bounds, coupon limits.
- API tests cover validation, coupon spending, a coupon running out (409), unknown coupons, pricing at another time.

## How pricing decides

1. Each rule is checked first. It is skipped in these cases:
   - It is turned off.
   - The time is before its `starts_at`, or at or after its `ends_at`.
   - It names customer groups, and the cart's group is not 1 of them.
   - It needs a coupon code that the cart did not give, or that has no uses left. Coupon codes ignore case and spaces at either end.
2. The rules that pass run in priority order, highest first. Rules with the same priority run in id order.
3. Each rule works on what is left of each line after the rules before it. A 10% rule after a $1 rule takes 10% of the price less $1.
4. A discount that would take a line below its floor is cut to the floor. A rule whose whole discount is cut away counts as skipped.
5. An exclusive rule runs only when no rule has applied yet. Once it applies, every later rule is skipped.
6. Rounding: every share is rounded half up to the nearest cent, once per line per rule. A cart-wide amount (a bundle or an order discount) is split across its lines in proportion to what is left of each. Each line first gets its whole cents, and the cents left over go 1 each to the lines with the largest remainders (ties to the earlier line). The parts always add up to the amount.

## API

All requests and answers are JSON. Send `Accept: application/json`.

| Method | Path | What it does |
|---|---|---|
| GET | `/api/products` | Lists the products by SKU. |
| POST | `/api/products` | Adds a product: `sku`, `name`, `price_cents`, `cost_cents` (optional), `category` (optional). |
| GET | `/api/rules` | Lists the rules by priority. |
| POST | `/api/rules` | Adds a rule: `name`, `type`, `params`, plus `priority`, `exclusive`, `active`, `starts_at`, `ends_at`, `customer_groups`, `coupon_code`, `usage_limit` (all optional). |
| GET | `/api/rules/{id}` | Shows 1 rule. |
| DELETE | `/api/rules/{id}` | Removes 1 rule. |
| POST | `/api/quotes` | Prices a cart: `items` (each a `sku` and a `quantity`), `customer_group`, `coupon_codes`, `at` (optional, to price at another time). Stores nothing. |
| POST | `/api/orders` | Prices a cart at the current time, stores it, spends the coupon uses. Answers 409 when a coupon has no uses left, 422 for an unknown coupon code. |
| GET | `/api/orders/{id}` | Shows a stored order with its full quote. |

## Sample quote

This request and answer are pasted from a real run against the sample shop (`php artisan migrate --seed`). The trade rule is skipped because the cart names no customer group, and the cups rule is skipped because the cart has no cups.

```text
POST /api/quotes
{
  "items": [
    {"sku": "TEA-01", "quantity": 3},
    {"sku": "POT-01", "quantity": 1},
    {"sku": "MUG-01", "quantity": 1}
  ],
  "coupon_codes": ["welcome10"],
  "at": "2026-11-08T22:00:00Z"
}
```

```json
{
  "priced_at": "2026-11-08T22:00:00+00:00",
  "customer_group": null,
  "coupon_codes": [
    "WELCOME10"
  ],
  "lines": [
    {
      "sku": "TEA-01",
      "name": "Green tea, 100 g",
      "quantity": 3,
      "unit_price_cents": 1250,
      "subtotal_cents": 3750,
      "discount_cents": 1488,
      "total_cents": 2262,
      "floor_cents": 1500,
      "discounts": [
        {
          "rule_id": 1,
          "name": "Tea: buy 2, get 1 free",
          "amount_cents": 1250
        },
        {
          "rule_id": 5,
          "name": "WELCOME10: $10 off $50",
          "amount_cents": 238
        }
      ]
    },
    {
      "sku": "POT-01",
      "name": "Cast iron teapot",
      "quantity": 1,
      "unit_price_cents": 6400,
      "subtotal_cents": 6400,
      "discount_cents": 751,
      "total_cents": 5649,
      "floor_cents": 3100,
      "discounts": [
        {
          "rule_id": 2,
          "name": "Teapot and mug for $80",
          "amount_cents": 156
        },
        {
          "rule_id": 5,
          "name": "WELCOME10: $10 off $50",
          "amount_cents": 595
        }
      ]
    },
    {
      "sku": "MUG-01",
      "name": "Stoneware mug",
      "quantity": 1,
      "unit_price_cents": 1800,
      "subtotal_cents": 1800,
      "discount_cents": 211,
      "total_cents": 1589,
      "floor_cents": 700,
      "discounts": [
        {
          "rule_id": 2,
          "name": "Teapot and mug for $80",
          "amount_cents": 44
        },
        {
          "rule_id": 5,
          "name": "WELCOME10: $10 off $50",
          "amount_cents": 167
        }
      ]
    }
  ],
  "subtotal_cents": 11950,
  "discount_cents": 2450,
  "total_cents": 9500,
  "rules": [
    {
      "rule_id": 1,
      "name": "Tea: buy 2, get 1 free",
      "type": "buy_x_get_y",
      "priority": 20,
      "exclusive": false,
      "result": "applied",
      "reason": "applied",
      "amount_cents": 1250
    },
    {
      "rule_id": 2,
      "name": "Teapot and mug for $80",
      "type": "bundle",
      "priority": 15,
      "exclusive": false,
      "result": "applied",
      "reason": "1 bundle(s)",
      "amount_cents": 200
    },
    {
      "rule_id": 3,
      "name": "Cups by the case",
      "type": "tiered",
      "priority": 10,
      "exclusive": false,
      "result": "skipped",
      "reason": "no item in the cart matches this rule",
      "amount_cents": 0
    },
    {
      "rule_id": 4,
      "name": "Trade 5%",
      "type": "percent_off",
      "priority": 5,
      "exclusive": false,
      "result": "skipped",
      "reason": "the rule is for customer groups trade",
      "amount_cents": 0
    },
    {
      "rule_id": 5,
      "name": "WELCOME10: $10 off $50",
      "type": "order_amount_off",
      "priority": 0,
      "exclusive": false,
      "result": "applied",
      "reason": "applied",
      "amount_cents": 1000
    }
  ],
  "unknown_coupon_codes": []
}
```

## Limits today

- There is no sign-in. Anyone who can reach the API can add rules and place orders.
- A bundle takes exactly 1 of each item it lists.
- A cart-wide discount that a floor cuts on 1 line is not moved to the other lines.
- Prices have 1 currency, and taxes are not handled.
- It has been built and tested on Linux only.

## Licence

MIT Licence. See [LICENSE](LICENSE).
