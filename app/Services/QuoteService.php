<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Rule;
use App\Pricing\Engine;
use App\Pricing\RuleSpec;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class QuoteService
{
    public function __construct(private Engine $engine) {}

    /**
     * Prices a cart with no side effects.
     *
     * @param  list<array{sku: string, quantity: int}>  $items
     * @param  list<string>  $codes
     */
    public function quote(array $items, ?string $group, array $codes, DateTimeImmutable $at): array
    {
        return $this->price($items, $group, $codes, $at, Rule::query()->get()->all());
    }

    /**
     * Prices a cart, stores it as an order and uses up 1 use of every coupon
     * rule that applied. The coupon rules are locked first, so 2 orders can
     * never both take the last use of a coupon.
     *
     * Nothing is stored when a code is unknown or a coupon has no uses left.
     *
     * @return array{0: ?Order, 1: array, 2: ?string} the order, the quote and why no order was stored
     */
    public function order(array $items, ?string $group, array $codes, DateTimeImmutable $at): array
    {
        return DB::transaction(function () use ($items, $group, $codes, $at) {
            $upper = array_map(fn ($c) => strtoupper(trim($c)), $codes);
            $locked = Rule::query()->whereIn('coupon_code', $upper)->orderBy('id')->lockForUpdate()->get();
            $others = Rule::query()->whereNotIn('id', $locked->pluck('id'))->get();
            $quote = $this->price($items, $group, $codes, $at, $locked->concat($others)->all());

            if ($quote['unknown_coupon_codes'] !== []) {
                return [null, $quote, 'unknown_code'];
            }
            foreach ($quote['rules'] as $r) {
                $rule = $locked->firstWhere('id', $r['rule_id']);
                if ($rule !== null && $r['result'] === 'skipped' && str_contains($r['reason'], 'no uses left')) {
                    return [null, $quote, 'used_up'];
                }
            }
            foreach ($quote['rules'] as $r) {
                $rule = $locked->firstWhere('id', $r['rule_id']);
                if ($rule !== null && $r['result'] === 'applied') {
                    $rule->increment('times_used');
                }
            }

            $order = Order::create([
                'customer_group' => $group,
                'coupon_codes' => $quote['coupon_codes'],
                'subtotal_cents' => $quote['subtotal_cents'],
                'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'],
                'quote' => $quote,
            ]);

            return [$order, $quote, null];
        });
    }

    /** @param  list<Rule>  $rules */
    private function price(array $items, ?string $group, array $codes, DateTimeImmutable $at, array $rules): array
    {
        $products = Product::query()->whereIn('sku', array_column($items, 'sku'))->get()
            ->mapWithKeys(fn (Product $p) => [$p->sku => $p->forEngine()])->all();
        $specs = array_map(fn (Rule $r) => RuleSpec::fromModel($r), $rules);
        $quote = $this->engine->quote($products, $items, $specs, $at, $group, $codes);

        $known = array_filter(array_map(fn (Rule $r) => $r->coupon_code, $rules));
        $quote['unknown_coupon_codes'] = array_values(array_diff($quote['coupon_codes'], $known));

        return $quote;
    }
}
