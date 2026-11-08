<?php

namespace Tests\Support;

use App\Pricing\RuleSpec;
use DateTimeImmutable;

/**
 * A second, slow, plain way to price a cart, written from the README rules
 * without the engine's code. It counts units 1 at a time, builds bundles 1
 * set at a time and hands out leftover cents 1 at a time. The oracle test
 * requires the engine to give exactly the same answer.
 */
final class ReferencePricer
{
    public static function totals(array $products, array $cart, array $rules, DateTimeImmutable $at, ?string $group, array $codes): array
    {
        $codes = array_map(fn ($c) => strtoupper(trim($c)), $codes);
        $lines = [];
        foreach ($cart as $item) {
            $p = $products[$item['sku']];
            $sub = $p['price_cents'] * $item['quantity'];
            $lines[] = [
                'sku' => $p['sku'], 'category' => $p['category'], 'qty' => $item['quantity'],
                'sub' => $sub,
                'floor' => $p['cost_cents'] === null ? 0 : min($sub, $p['cost_cents'] * $item['quantity']),
                'left' => $sub,
                'got' => [],
            ];
        }

        // Highest priority first, then lowest id, by picking the best 1 each time.
        $todo = $rules;
        $ordered = [];
        while ($todo !== []) {
            $best = null;
            foreach ($todo as $k => $r) {
                if ($best === null || $r->priority > $todo[$best]->priority
                    || ($r->priority === $todo[$best]->priority && $r->id < $todo[$best]->id)) {
                    $best = $k;
                }
            }
            $ordered[] = $todo[$best];
            unset($todo[$best]);
        }

        $applied = [];
        $anyApplied = false;
        $stopped = false;
        foreach ($ordered as $r) {
            if (! self::eligible($r, $at, $group, $codes) || $stopped || ($r->exclusive && $anyApplied)) {
                continue;
            }
            $want = self::want($r, $lines);
            $given = 0;
            foreach ($want as $i => $amount) {
                $take = $amount;
                while ($take > 0 && $lines[$i]['left'] - $take < $lines[$i]['floor']) {
                    $take--;
                }
                if ($take > 0) {
                    $lines[$i]['left'] -= $take;
                    $lines[$i]['got'][] = [$r->id, $take];
                    $given += $take;
                }
            }
            if ($given > 0) {
                $anyApplied = true;
                $applied[$r->id] = $given;
                if ($r->exclusive) {
                    $stopped = true;
                }
            }
        }

        return [
            'lines' => array_map(fn ($l) => ['sku' => $l['sku'], 'total' => $l['left'], 'got' => $l['got']], $lines),
            'total' => array_sum(array_column($lines, 'left')),
            'applied' => $applied,
        ];
    }

    private static function eligible(RuleSpec $r, DateTimeImmutable $at, ?string $group, array $codes): bool
    {
        if (! $r->active) {
            return false;
        }
        if ($r->startsAt !== null && $at->getTimestamp() < $r->startsAt->getTimestamp()) {
            return false;
        }
        if ($r->endsAt !== null && $at->getTimestamp() >= $r->endsAt->getTimestamp()) {
            return false;
        }
        if ($r->groups !== null && ! in_array($group, $r->groups, true)) {
            return false;
        }
        if ($r->couponCode !== null) {
            if (! in_array(strtoupper($r->couponCode), $codes, true)) {
                return false;
            }
            if ($r->usageLimit !== null && $r->timesUsed >= $r->usageLimit) {
                return false;
            }
        }

        return true;
    }

    private static function halfUp(int $num, int $den): int
    {
        $q = intdiv($num, $den);

        return ($num % $den) * 2 >= $den ? $q + 1 : $q;
    }

    private static function hits(array $p, array $l): bool
    {
        return (empty($p['skus']) || in_array($l['sku'], $p['skus'], true))
            && (empty($p['categories']) || in_array($l['category'], $p['categories'], true));
    }

    private static function want(RuleSpec $r, array $lines): array
    {
        $p = $r->params;
        $out = [];
        switch ($r->type) {
            case 'percent_off':
                foreach ($lines as $i => $l) {
                    if (self::hits($p, $l)) {
                        $out[$i] = self::halfUp($l['left'] * $p['basis_points'], 10000);
                    }
                }
                break;
            case 'amount_off':
                foreach ($lines as $i => $l) {
                    if (self::hits($p, $l)) {
                        $out[$i] = ($p['per'] ?? 'unit') === 'unit' ? $p['amount_cents'] * $l['qty'] : $p['amount_cents'];
                    }
                }
                break;
            case 'buy_x_get_y':
                $size = $p['buy'] + $p['get'];
                foreach ($lines as $i => $l) {
                    if (! self::hits($p, $l)) {
                        continue;
                    }
                    $free = 0;
                    for ($unit = 0; $unit < $l['qty']; $unit++) {
                        $groupNo = intdiv($unit, $size);
                        $complete = ($groupNo + 1) * $size <= $l['qty'];
                        if ($complete && $unit % $size >= $p['buy']) {
                            $free++;
                        }
                    }
                    $out[$i] = self::halfUp($l['left'] * $free * ($p['basis_points'] ?? 10000), $l['qty'] * 10000);
                }
                break;
            case 'tiered':
                foreach ($lines as $i => $l) {
                    if (! self::hits($p, $l)) {
                        continue;
                    }
                    $bp = 0;
                    foreach ($p['tiers'] as $t) {
                        if ($l['qty'] >= $t['min_quantity']) {
                            $bp = max($bp, $t['basis_points']);
                        }
                    }
                    $out[$i] = self::halfUp($l['left'] * $bp, 10000);
                }
                break;
            case 'bundle':
                $where = [];
                foreach ($lines as $i => $l) {
                    if (in_array($l['sku'], $p['skus'], true)) {
                        $where[$l['sku']] = $i;
                    }
                }
                if (count($where) < count($p['skus'])) {
                    break;
                }
                $stock = array_map(fn ($i) => $lines[$i]['qty'], $where);
                $sets = 0;
                while (min($stock) > 0) {
                    foreach ($stock as $k => $n) {
                        $stock[$k] = $n - 1;
                    }
                    $sets++;
                }
                $w = [];
                foreach ($where as $i) {
                    $w[$i] = intdiv($lines[$i]['left'] * $sets, $lines[$i]['qty']);
                }
                $off = array_sum($w) - $p['price_cents'] * $sets;
                $out = $off > 0 ? self::spread($off, $w) : [];
                break;
            case 'order_amount_off':
                $sum = array_sum(array_column($lines, 'left'));
                if ($sum >= ($p['min_subtotal_cents'] ?? 0)) {
                    $out = self::spread(min($p['amount_cents'], $sum), array_column($lines, 'left'));
                }
                break;
        }

        return $out;
    }

    /** Whole cents first, then 1 cent at a time to the largest remainder, ties to the earlier key. */
    private static function spread(int $total, array $w): array
    {
        $sum = array_sum($w);
        $out = [];
        $rem = [];
        foreach ($w as $k => $x) {
            $out[$k] = $sum === 0 ? 0 : intdiv($total * $x, $sum);
            $rem[$k] = $sum === 0 ? 0 : ($total * $x) % $sum;
        }
        if ($sum === 0) {
            return $out;
        }
        $left = $total - array_sum($out);
        $used = [];
        while ($left > 0) {
            $pick = null;
            foreach ($w as $k => $x) {
                if (! isset($used[$k]) && ($pick === null || $rem[$k] > $rem[$pick])) {
                    $pick = $k;
                }
            }
            $used[$pick] = true;
            $out[$pick]++;
            $left--;
        }

        return $out;
    }
}
