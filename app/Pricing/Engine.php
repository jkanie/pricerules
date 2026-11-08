<?php

namespace App\Pricing;

use DateTimeImmutable;

/**
 * Prices a cart against a set of rules.
 *
 * All money is in integer cents. The engine reads no database: the caller
 * passes the products, the rules and the context, which keeps every quote
 * repeatable and lets the tests compare it with a separate reference
 * calculation.
 *
 * Order of work:
 *  1. Each rule is checked for eligibility (turned on, date window, customer
 *     group, coupon code, uses left).
 *  2. Eligible rules run in priority order (highest first, then lowest id).
 *  3. Each rule discounts what is left of each line after the rules before
 *     it, and never takes a line below its floor (cost times quantity when
 *     the product has a cost, otherwise 0).
 *  4. An exclusive rule runs only when no rule has applied yet, and once it
 *     applies, every later rule is skipped.
 */
final class Engine
{
    /**
     * @param  array<string, array{sku: string, name: string, price_cents: int, cost_cents: ?int, category: ?string}>  $products  keyed by SKU
     * @param  list<array{sku: string, quantity: int}>  $cart
     * @param  list<RuleSpec>  $rules
     * @param  list<string>  $codes  coupon codes given with the cart
     * @return array<string, mixed>
     */
    public function quote(
        array $products,
        array $cart,
        array $rules,
        DateTimeImmutable $at,
        ?string $group = null,
        array $codes = [],
    ): array {
        $codes = array_map(fn ($c) => strtoupper(trim($c)), $codes);
        $lines = [];
        foreach ($cart as $i => $item) {
            $p = $products[$item['sku']];
            $qty = $item['quantity'];
            $subtotal = $p['price_cents'] * $qty;
            $floor = $p['cost_cents'] === null ? 0 : min($subtotal, $p['cost_cents'] * $qty);
            $lines[$i] = [
                'sku' => $p['sku'],
                'name' => $p['name'],
                'category' => $p['category'],
                'quantity' => $qty,
                'unit_price_cents' => $p['price_cents'],
                'subtotal_cents' => $subtotal,
                'floor_cents' => $floor,
                'remaining' => $subtotal,
                'discounts' => [],
            ];
        }

        usort($rules, fn (RuleSpec $a, RuleSpec $b) => [$b->priority, $a->id] <=> [$a->priority, $b->id]);

        $report = [];
        $anyApplied = false;
        $stoppedBy = null;
        foreach ($rules as $rule) {
            $why = $this->ineligible($rule, $at, $group, $codes);
            if ($why === null && $stoppedBy !== null) {
                $why = "exclusive rule {$stoppedBy} applied first";
            }
            if ($why === null && $rule->exclusive && $anyApplied) {
                $why = 'this rule is exclusive and an earlier rule already applied';
            }
            if ($why !== null) {
                $report[] = $this->entry($rule, 'skipped', $why, 0);

                continue;
            }

            [$wanted, $note] = $this->wanted($rule, $lines);
            $given = 0;
            $capped = false;
            foreach ($wanted as $i => $amount) {
                $room = $lines[$i]['remaining'] - $lines[$i]['floor_cents'];
                $take = min($amount, $room);
                if ($take < $amount) {
                    $capped = true;
                }
                if ($take > 0) {
                    $lines[$i]['remaining'] -= $take;
                    $lines[$i]['discounts'][] = ['rule_id' => $rule->id, 'name' => $rule->name, 'amount_cents' => $take];
                    $given += $take;
                }
            }

            if ($given === 0) {
                $why = $note ?? ($wanted === [] ? 'no item in the cart matches this rule' : 'every matching line is already at its floor');
                $report[] = $this->entry($rule, 'skipped', $why, 0);

                continue;
            }

            $anyApplied = true;
            $why = $note ?? 'applied';
            if ($capped) {
                $why .= ', cut to keep the line at its floor';
            }
            $report[] = $this->entry($rule, 'applied', $why, $given);
            if ($rule->exclusive) {
                $stoppedBy = $rule->id;
            }
        }

        $outLines = [];
        $subtotal = $total = 0;
        foreach ($lines as $line) {
            $subtotal += $line['subtotal_cents'];
            $total += $line['remaining'];
            $outLines[] = [
                'sku' => $line['sku'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'subtotal_cents' => $line['subtotal_cents'],
                'discount_cents' => $line['subtotal_cents'] - $line['remaining'],
                'total_cents' => $line['remaining'],
                'floor_cents' => $line['floor_cents'],
                'discounts' => $line['discounts'],
            ];
        }

        return [
            'priced_at' => $at->format(DATE_ATOM),
            'customer_group' => $group,
            'coupon_codes' => $codes,
            'lines' => $outLines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $subtotal - $total,
            'total_cents' => $total,
            'rules' => $report,
        ];
    }

    private function ineligible(RuleSpec $r, DateTimeImmutable $at, ?string $group, array $codes): ?string
    {
        if (! $r->active) {
            return 'the rule is turned off';
        }
        if ($r->startsAt !== null && $at < $r->startsAt) {
            return 'the rule starts at '.$r->startsAt->format(DATE_ATOM);
        }
        if ($r->endsAt !== null && $at >= $r->endsAt) {
            return 'the rule ended at '.$r->endsAt->format(DATE_ATOM);
        }
        if ($r->groups !== null && ($group === null || ! in_array($group, $r->groups, true))) {
            return 'the rule is for customer groups '.implode(', ', $r->groups);
        }
        if ($r->couponCode !== null) {
            if (! in_array(strtoupper($r->couponCode), $codes, true)) {
                return 'coupon code '.$r->couponCode.' was not given';
            }
            if ($r->usageLimit !== null && $r->timesUsed >= $r->usageLimit) {
                return 'coupon code '.$r->couponCode.' has no uses left';
            }
        }

        return null;
    }

    /**
     * The discount each rule asks for, per line index, before floors.
     *
     * @return array{0: array<int, int>, 1: ?string}
     */
    private function wanted(RuleSpec $r, array $lines): array
    {
        $p = $r->params;
        $match = fn (array $l) => $this->targets($p, $l);

        switch ($r->type) {
            case 'percent_off':
                $out = [];
                foreach ($lines as $i => $l) {
                    if ($match($l)) {
                        $out[$i] = Money::percentOf($l['remaining'], $p['basis_points']);
                    }
                }

                return [$out, null];

            case 'amount_off':
                $out = [];
                foreach ($lines as $i => $l) {
                    if ($match($l)) {
                        $per = ($p['per'] ?? 'unit') === 'unit' ? $l['quantity'] : 1;
                        $out[$i] = $p['amount_cents'] * $per;
                    }
                }

                return [$out, null];

            case 'buy_x_get_y':
                $out = [];
                $group = $p['buy'] + $p['get'];
                foreach ($lines as $i => $l) {
                    if ($match($l)) {
                        $free = intdiv($l['quantity'], $group) * $p['get'];
                        $out[$i] = $free === 0 ? 0 : Money::share($l['remaining'], $free, $l['quantity'], $p['basis_points'] ?? 10000);
                    }
                }

                return [$out, $out !== [] && max($out) === 0 ? "needs {$group} units of 1 item" : null];

            case 'tiered':
                $out = [];
                foreach ($lines as $i => $l) {
                    if (! $match($l)) {
                        continue;
                    }
                    $bp = 0;
                    foreach ($p['tiers'] as $t) {
                        if ($l['quantity'] >= $t['min_quantity'] && $t['basis_points'] > $bp) {
                            $bp = $t['basis_points'];
                        }
                    }
                    $out[$i] = Money::percentOf($l['remaining'], $bp);
                }

                return [$out, $out !== [] && max($out) === 0 ? 'no line reaches the lowest tier' : null];

            case 'bundle':
                $idx = [];
                foreach ($p['skus'] as $sku) {
                    foreach ($lines as $i => $l) {
                        if ($l['sku'] === $sku) {
                            $idx[$sku] = $i;
                        }
                    }
                }
                if (count($idx) < count($p['skus'])) {
                    return [[], 'the cart lacks '.implode(', ', array_diff($p['skus'], array_keys($idx)))];
                }
                $sets = min(array_map(fn ($i) => $lines[$i]['quantity'], $idx));
                $value = 0;
                $weights = [];
                foreach ($idx as $i) {
                    $part = intdiv($lines[$i]['remaining'] * $sets, $lines[$i]['quantity']);
                    $weights[$i] = $part;
                    $value += $part;
                }
                $off = $value - $p['price_cents'] * $sets;
                if ($off <= 0) {
                    return [array_fill_keys(array_values($idx), 0), 'the bundle price is not below the items it covers'];
                }

                return [Money::allocate($off, $weights), "{$sets} bundle(s)"];

            case 'order_amount_off':
                $sum = array_sum(array_column($lines, 'remaining'));
                if ($sum < ($p['min_subtotal_cents'] ?? 0)) {
                    return [[], 'the cart is below '.Money::format($p['min_subtotal_cents'])];
                }
                $weights = array_map(fn ($l) => $l['remaining'], $lines);

                return [Money::allocate(min($p['amount_cents'], $sum), $weights), null];
        }

        return [[], 'unknown rule type'];
    }

    private function targets(array $p, array $line): bool
    {
        if (! empty($p['skus']) && ! in_array($line['sku'], $p['skus'], true)) {
            return false;
        }
        if (! empty($p['categories']) && ! in_array($line['category'], $p['categories'], true)) {
            return false;
        }

        return true;
    }

    private function entry(RuleSpec $r, string $result, string $reason, int $amount): array
    {
        return [
            'rule_id' => $r->id,
            'name' => $r->name,
            'type' => $r->type,
            'priority' => $r->priority,
            'exclusive' => $r->exclusive,
            'result' => $result,
            'reason' => $reason,
            'amount_cents' => $amount,
        ];
    }
}
