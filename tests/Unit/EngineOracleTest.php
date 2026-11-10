<?php

namespace Tests\Unit;

use App\Pricing\Engine;
use App\Pricing\RuleSpec;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReferencePricer;

/**
 * Prices 500 seeded random carts against seeded random rule sets with the
 * engine and with the slow reference pricer, and requires the same totals,
 * the same discounts per line and the same rules applied. It also checks
 * the rules every quote must keep, whatever the rules are.
 */
class EngineOracleTest extends TestCase
{
    public const CASES = 500;

    public function test_engine_matches_the_reference_on_random_carts(): void
    {
        $engine = new Engine;
        for ($seed = 1; $seed <= self::CASES; $seed++) {
            [$products, $cart, $rules, $at, $group, $codes] = self::scenario($seed);
            $got = $engine->quote($products, $cart, $rules, $at, $group, $codes);
            $want = ReferencePricer::totals($products, $cart, $rules, $at, $group, $codes);

            $this->assertSame($want['total'], $got['total_cents'], "seed {$seed}: total");
            foreach ($want['lines'] as $i => $line) {
                $this->assertSame($line['total'], $got['lines'][$i]['total_cents'], "seed {$seed}: line {$i}");
                $this->assertSame(
                    $line['got'],
                    array_map(fn ($d) => [$d['rule_id'], $d['amount_cents']], $got['lines'][$i]['discounts']),
                    "seed {$seed}: discounts on line {$i}",
                );
            }
            $applied = [];
            foreach ($got['rules'] as $r) {
                if ($r['result'] === 'applied') {
                    $applied[$r['rule_id']] = $r['amount_cents'];
                }
            }
            $this->assertSame($want['applied'], $applied, "seed {$seed}: rules applied");
            $this->assertInvariants($got, $rules, $seed);
        }
    }

    public function test_the_order_rules_are_stored_in_does_not_matter(): void
    {
        $engine = new Engine;
        for ($seed = 1; $seed <= 100; $seed++) {
            [$products, $cart, $rules, $at, $group, $codes] = self::scenario($seed);
            $a = $engine->quote($products, $cart, $rules, $at, $group, $codes);
            mt_srand($seed * 7919);
            shuffle($rules);
            $b = $engine->quote($products, $cart, $rules, $at, $group, $codes);
            $this->assertSame($a, $b, "seed {$seed}");
        }
    }

    private function assertInvariants(array $q, array $rules, int $seed): void
    {
        $sum = 0;
        foreach ($q['lines'] as $l) {
            $this->assertGreaterThanOrEqual($l['floor_cents'], $l['total_cents'], "seed {$seed}: floor");
            $this->assertGreaterThanOrEqual(0, $l['total_cents'], "seed {$seed}: not below 0");
            $this->assertSame($l['subtotal_cents'] - $l['discount_cents'], $l['total_cents'], "seed {$seed}: line sum");
            $this->assertSame($l['discount_cents'], array_sum(array_column($l['discounts'], 'amount_cents')), "seed {$seed}: line parts");
            $sum += $l['total_cents'];
        }
        $this->assertSame($sum, $q['total_cents'], "seed {$seed}: lines add up");
        $this->assertSame($q['subtotal_cents'] - $q['discount_cents'], $q['total_cents'], "seed {$seed}: totals");
        $this->assertCount(count($rules), $q['rules'], "seed {$seed}: every rule explained");
        $applied = array_values(array_filter($q['rules'], fn ($r) => $r['result'] === 'applied'));
        $exclusive = array_filter($applied, fn ($r) => $r['exclusive']);
        if ($exclusive !== []) {
            $this->assertCount(1, $applied, "seed {$seed}: an exclusive rule applies alone");
        }
        foreach ($q['rules'] as $r) {
            $this->assertNotSame('', $r['reason'], "seed {$seed}: every rule has a reason");
        }
    }

    /** @return array{0: array, 1: array, 2: list<RuleSpec>, 3: DateTimeImmutable, 4: ?string, 5: list<string>} */
    public static function scenario(int $seed): array
    {
        mt_srand($seed);
        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $at = new DateTimeImmutable('2026-06-15T12:00:00+00:00');

        $products = [];
        $count = mt_rand(2, 6);
        for ($i = 1; $i <= $count; $i++) {
            $price = mt_rand(1, 20000);
            $products["SKU{$i}"] = [
                'sku' => "SKU{$i}",
                'name' => "Item {$i}",
                'price_cents' => $price,
                'cost_cents' => mt_rand(0, 2) === 0 ? null : mt_rand(0, intdiv($price * 12, 10)),
                'category' => $pick(['tools', 'books', null]),
            ];
        }
        $skus = array_keys($products);

        $someSkus = function () use ($skus) {
            $s = $skus;
            shuffle($s);

            return array_slice($s, 0, mt_rand(1, count($s)));
        };
        $targets = fn () => match (mt_rand(0, 3)) {
            0 => [],
            1 => ['skus' => $someSkus()],
            2 => ['categories' => [mt_rand(0, 1) ? 'tools' : 'books']],
            default => ['skus' => $someSkus(), 'categories' => ['tools']],
        };

        $rules = [];
        $n = mt_rand(0, 7);
        for ($id = 1; $id <= $n; $id++) {
            $type = $pick(['percent_off', 'amount_off', 'buy_x_get_y', 'tiered', 'bundle', 'order_amount_off']);
            $params = match ($type) {
                'percent_off' => ['basis_points' => mt_rand(1, 10000)] + $targets(),
                'amount_off' => ['amount_cents' => mt_rand(1, 3000), 'per' => $pick(['unit', 'line'])] + $targets(),
                'buy_x_get_y' => ['buy' => mt_rand(1, 4), 'get' => mt_rand(1, 3), 'basis_points' => $pick([10000, 5000, mt_rand(1, 10000)])] + $targets(),
                'tiered' => ['tiers' => array_map(fn () => ['min_quantity' => mt_rand(1, 20), 'basis_points' => mt_rand(1, 5000)], range(1, mt_rand(1, 3)))] + $targets(),
                'bundle' => mt_rand(0, 1)
                    ? ['skus' => array_slice($someSkus(), 0, max(2, mt_rand(2, 3))), 'price_cents' => mt_rand(0, 30000)]
                    : ['items' => array_map(fn ($s) => ['sku' => $s, 'quantity' => mt_rand(1, 3)], $someSkus()), 'price_cents' => mt_rand(0, 30000)],
                'order_amount_off' => ['amount_cents' => mt_rand(1, 20000), 'min_subtotal_cents' => mt_rand(0, 50000)],
            };
            if ($type === 'bundle' && isset($params['skus']) && count($params['skus']) < 2) {
                $params['skus'] = array_slice($skus, 0, 2);
            }
            $coupon = mt_rand(0, 3) === 0 ? 'CODE'.$id : null;
            $limit = $coupon !== null && mt_rand(0, 1) ? mt_rand(1, 3) : null;
            $rules[] = new RuleSpec(
                id: $id,
                name: "Rule {$id}",
                type: $type,
                params: $params,
                priority: mt_rand(-3, 3),
                exclusive: mt_rand(0, 4) === 0,
                active: mt_rand(0, 9) !== 0,
                startsAt: mt_rand(0, 3) === 0 ? $at->modify(mt_rand(-2, 2).' days') : null,
                endsAt: mt_rand(0, 3) === 0 ? $at->modify(mt_rand(-2, 2).' days') : null,
                groups: mt_rand(0, 3) === 0 ? [$pick(['trade', 'staff'])] : null,
                couponCode: $coupon,
                usageLimit: $limit,
                timesUsed: $limit === null ? 0 : mt_rand(0, 3),
            );
        }

        $cartSkus = $someSkus();
        $cart = array_map(fn ($s) => ['sku' => $s, 'quantity' => mt_rand(1, 25)], array_slice($cartSkus, 0, mt_rand(1, min(5, count($cartSkus)))));
        $group = $pick([null, 'trade', 'staff']);
        $codes = array_values(array_filter(array_map(fn ($id) => mt_rand(0, 1) ? strtolower("code{$id}") : null, range(1, max(1, $n)))));

        return [$products, $cart, $rules, $at, $group, $codes];
    }
}
