<?php

namespace Tests\Unit;

use App\Pricing\Engine;
use App\Pricing\Money;
use App\Pricing\RuleSpec;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->at = new DateTimeImmutable('2026-06-15T12:00:00+00:00');
    }

    private function products(): array
    {
        return [
            'PEN' => ['sku' => 'PEN', 'name' => 'Pen', 'price_cents' => 333, 'cost_cents' => null, 'category' => 'office'],
            'INK' => ['sku' => 'INK', 'name' => 'Ink', 'price_cents' => 1000, 'cost_cents' => 800, 'category' => 'office'],
            'MUG' => ['sku' => 'MUG', 'name' => 'Mug', 'price_cents' => 1200, 'cost_cents' => null, 'category' => 'kitchen'],
        ];
    }

    private function quote(array $cart, array $rules, ?string $group = null, array $codes = [], ?DateTimeImmutable $at = null): array
    {
        return (new Engine)->quote($this->products(), $cart, $rules, $at ?? $this->at, $group, $codes);
    }

    private function rule(int $id, string $type, array $params, array $more = []): RuleSpec
    {
        return new RuleSpec(...['id' => $id, 'name' => "R{$id}", 'type' => $type, 'params' => $params] + $more);
    }

    private function ruleResult(array $q, int $id): array
    {
        foreach ($q['rules'] as $r) {
            if ($r['rule_id'] === $id) {
                return $r;
            }
        }
        $this->fail("rule {$id} not reported");
    }

    public function test_percent_off_rounds_half_up(): void
    {
        $q = $this->quote([['sku' => 'PEN', 'quantity' => 1]], [$this->rule(1, 'percent_off', ['basis_points' => 5000])]);
        $this->assertSame(167, $q['discount_cents']); // 166.5 cents rounds up
        $this->assertSame(166, $q['total_cents']);
    }

    public function test_rules_stack_on_what_is_left(): void
    {
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1]], [
            $this->rule(1, 'percent_off', ['basis_points' => 1000], ['priority' => 5]),
            $this->rule(2, 'amount_off', ['amount_cents' => 100], ['priority' => 1]),
        ]);
        $this->assertSame(1200 - 120 - 100, $q['total_cents']);
        $this->assertSame([1, 2], array_column($q['lines'][0]['discounts'], 'rule_id'));
    }

    public function test_an_exclusive_rule_stops_later_rules(): void
    {
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1]], [
            $this->rule(1, 'percent_off', ['basis_points' => 2000], ['priority' => 9, 'exclusive' => true]),
            $this->rule(2, 'amount_off', ['amount_cents' => 100]),
        ]);
        $this->assertSame(960, $q['total_cents']);
        $this->assertSame('exclusive rule 1 applied first', $this->ruleResult($q, 2)['reason']);
    }

    public function test_an_exclusive_rule_after_an_applied_rule_is_skipped(): void
    {
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1]], [
            $this->rule(1, 'amount_off', ['amount_cents' => 100], ['priority' => 9]),
            $this->rule(2, 'percent_off', ['basis_points' => 5000], ['exclusive' => true]),
        ]);
        $this->assertSame(1100, $q['total_cents']);
        $this->assertSame('skipped', $this->ruleResult($q, 2)['result']);
    }

    public function test_the_cost_floor_cuts_a_discount(): void
    {
        $q = $this->quote([['sku' => 'INK', 'quantity' => 2]], [$this->rule(1, 'percent_off', ['basis_points' => 5000])]);
        $this->assertSame(1600, $q['total_cents']);
        $this->assertSame('applied, cut to keep the line at its floor', $this->ruleResult($q, 1)['reason']);
        $q = $this->quote([['sku' => 'INK', 'quantity' => 2]], [
            $this->rule(1, 'percent_off', ['basis_points' => 5000]),
            $this->rule(2, 'amount_off', ['amount_cents' => 1]),
        ]);
        $this->assertSame('every matching line is already at its floor', $this->ruleResult($q, 2)['reason']);
    }

    public function test_a_line_never_goes_below_0(): void
    {
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1]], [$this->rule(1, 'amount_off', ['amount_cents' => 5000])]);
        $this->assertSame(0, $q['total_cents']);
    }

    public function test_buy_2_get_1_free(): void
    {
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 7]], [$this->rule(1, 'buy_x_get_y', ['buy' => 2, 'get' => 1, 'skus' => ['MUG']])]);
        $this->assertSame(2400, $q['discount_cents']); // 2 complete groups of 3
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 2]], [$this->rule(1, 'buy_x_get_y', ['buy' => 2, 'get' => 1])]);
        $this->assertSame('needs 3 units of 1 item', $this->ruleResult($q, 1)['reason']);
    }

    public function test_tiers_use_the_best_tier_reached(): void
    {
        $tiers = ['tiers' => [['min_quantity' => 10, 'basis_points' => 1000], ['min_quantity' => 50, 'basis_points' => 2000]]];
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 10]], [$this->rule(1, 'tiered', $tiers)]);
        $this->assertSame(1200, $q['discount_cents']);
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 9]], [$this->rule(1, 'tiered', $tiers)]);
        $this->assertSame('no line reaches the lowest tier', $this->ruleResult($q, 1)['reason']);
    }

    public function test_bundles_count_complete_sets(): void
    {
        $q = $this->quote(
            [['sku' => 'PEN', 'quantity' => 3], ['sku' => 'MUG', 'quantity' => 2]],
            [$this->rule(1, 'bundle', ['skus' => ['PEN', 'MUG'], 'price_cents' => 1000])],
        );
        // 2 sets: (333 + 1200) x 2 = 3066, bundle price 2000, 1066 off.
        $this->assertSame(1066, $q['discount_cents']);
        $this->assertSame('2 bundle(s)', $this->ruleResult($q, 1)['reason']);
        $q = $this->quote([['sku' => 'PEN', 'quantity' => 3]], [$this->rule(1, 'bundle', ['skus' => ['PEN', 'MUG'], 'price_cents' => 1000])]);
        $this->assertSame('the cart lacks MUG', $this->ruleResult($q, 1)['reason']);
    }

    public function test_bundles_take_a_quantity_per_item(): void
    {
        $rule = $this->rule(1, 'bundle', ['items' => [['sku' => 'PEN', 'quantity' => 1], ['sku' => 'MUG', 'quantity' => 2]], 'price_cents' => 2000]);
        $q = $this->quote([['sku' => 'PEN', 'quantity' => 3], ['sku' => 'MUG', 'quantity' => 5]], [$rule]);
        // 2 sets of 1 pen and 2 mugs: pens 999 x 2/3 = 666, mugs 6000 x 4/5 = 4800, 5466 - 4000 = 1466 off.
        $this->assertSame(1466, $q['discount_cents']);
        $this->assertSame('2 bundle(s)', $this->ruleResult($q, 1)['reason']);
        $this->assertSame(1466, array_sum(array_column($q['lines'], 'discount_cents')));

        $q = $this->quote([['sku' => 'PEN', 'quantity' => 3], ['sku' => 'MUG', 'quantity' => 1]], [$rule]);
        $this->assertSame(0, $q['discount_cents']);
        $this->assertSame('the cart has too few of MUG (1 of 2)', $this->ruleResult($q, 1)['reason']);

        $q = $this->quote([['sku' => 'MUG', 'quantity' => 5]], [$rule]);
        $this->assertSame('the cart lacks PEN', $this->ruleResult($q, 1)['reason']);
    }

    public function test_a_bundle_of_1_item_is_a_multi_buy(): void
    {
        $rule = $this->rule(1, 'bundle', ['items' => [['sku' => 'MUG', 'quantity' => 3]], 'price_cents' => 3000]);
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 7]], [$rule]);
        // 2 sets of 3 mugs: 8400 x 6/7 = 7200, 7200 - 6000 = 1200 off. The 7th mug keeps its price.
        $this->assertSame(1200, $q['discount_cents']);
        $this->assertSame('2 bundle(s)', $this->ruleResult($q, 1)['reason']);
    }

    public function test_skus_and_items_of_quantity_1_price_the_same(): void
    {
        $cart = [['sku' => 'PEN', 'quantity' => 3], ['sku' => 'MUG', 'quantity' => 2]];
        $old = $this->quote($cart, [$this->rule(1, 'bundle', ['skus' => ['PEN', 'MUG'], 'price_cents' => 1000])]);
        $new = $this->quote($cart, [$this->rule(1, 'bundle', ['items' => [['sku' => 'PEN', 'quantity' => 1], ['sku' => 'MUG', 'quantity' => 1]], 'price_cents' => 1000])]);
        $this->assertSame($old, $new);
    }

    public function test_order_amount_off_needs_the_minimum(): void
    {
        $rule = $this->rule(1, 'order_amount_off', ['amount_cents' => 500, 'min_subtotal_cents' => 2000]);
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1]], [$rule]);
        $this->assertSame('the cart is below $20.00', $this->ruleResult($q, 1)['reason']);
        $q = $this->quote([['sku' => 'MUG', 'quantity' => 1], ['sku' => 'PEN', 'quantity' => 3]], [$rule]);
        $this->assertSame(500, $q['discount_cents']);
        $this->assertSame([273, 227], array_column($q['lines'], 'discount_cents'));
    }

    public function test_the_date_window_includes_its_start_and_excludes_its_end(): void
    {
        $r = $this->rule(1, 'amount_off', ['amount_cents' => 100], [
            'startsAt' => $this->at, 'endsAt' => $this->at->modify('+1 day'),
        ]);
        $cart = [['sku' => 'MUG', 'quantity' => 1]];
        $this->assertSame(100, $this->quote($cart, [$r])['discount_cents']);
        $this->assertSame(0, $this->quote($cart, [$r], at: $this->at->modify('-1 second'))['discount_cents']);
        $this->assertSame(0, $this->quote($cart, [$r], at: $this->at->modify('+1 day'))['discount_cents']);
    }

    public function test_customer_groups(): void
    {
        $r = $this->rule(1, 'amount_off', ['amount_cents' => 100], ['groups' => ['trade']]);
        $cart = [['sku' => 'MUG', 'quantity' => 1]];
        $this->assertSame(0, $this->quote($cart, [$r])['discount_cents']);
        $this->assertSame(100, $this->quote($cart, [$r], 'trade')['discount_cents']);
    }

    public function test_coupons_ignore_case_and_respect_their_limit(): void
    {
        $r = $this->rule(1, 'amount_off', ['amount_cents' => 100], ['couponCode' => 'SPRING', 'usageLimit' => 2, 'timesUsed' => 1]);
        $cart = [['sku' => 'MUG', 'quantity' => 1]];
        $this->assertSame('coupon code SPRING was not given', $this->ruleResult($this->quote($cart, [$r]), 1)['reason']);
        $this->assertSame(100, $this->quote($cart, [$r], codes: [' spring '])['discount_cents']);
        $used = $this->rule(1, 'amount_off', ['amount_cents' => 100], ['couponCode' => 'SPRING', 'usageLimit' => 2, 'timesUsed' => 2]);
        $this->assertSame('coupon code SPRING has no uses left', $this->ruleResult($this->quote($cart, [$used], codes: ['SPRING']), 1)['reason']);
    }

    public function test_targets_by_sku_and_category(): void
    {
        $cart = [['sku' => 'PEN', 'quantity' => 1], ['sku' => 'MUG', 'quantity' => 1]];
        $q = $this->quote($cart, [$this->rule(1, 'amount_off', ['amount_cents' => 10, 'categories' => ['kitchen']])]);
        $this->assertSame([0, 10], array_column($q['lines'], 'discount_cents'));
        $q = $this->quote($cart, [$this->rule(1, 'amount_off', ['amount_cents' => 10, 'skus' => ['PEN']])]);
        $this->assertSame([10, 0], array_column($q['lines'], 'discount_cents'));
        $q = $this->quote($cart, [$this->rule(1, 'amount_off', ['amount_cents' => 10, 'skus' => ['INK']])]);
        $this->assertSame('no item in the cart matches this rule', $this->ruleResult($q, 1)['reason']);
    }

    public function test_allocation_always_adds_up(): void
    {
        $this->assertSame([34, 33, 33], Money::allocate(100, [1, 1, 1]));
        $this->assertSame([0, 1], Money::allocate(1, [1, 2]));
        $this->assertSame([0, 0], Money::allocate(5, [0, 0]));
        mt_srand(42);
        for ($i = 0; $i < 1000; $i++) {
            $w = array_map(fn () => mt_rand(0, 10000), range(1, mt_rand(1, 6)));
            $t = mt_rand(0, 100000);
            $this->assertSame(array_sum($w) === 0 ? 0 : $t, array_sum(Money::allocate($t, $w)));
        }
    }
}
