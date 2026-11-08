<?php

namespace App\Pricing;

/**
 * Integer-cent arithmetic. Every rounding step rounds half up to the nearest
 * cent, and every amount here is 0 or more.
 */
final class Money
{
    /** $bp basis points (10,000 = 100%) of $cents, rounded half up. */
    public static function percentOf(int $cents, int $bp): int
    {
        return intdiv($cents * $bp + 5000, 10000);
    }

    /** $bp basis points of the share $part / $whole of $cents, rounded half up. */
    public static function share(int $cents, int $part, int $whole, int $bp): int
    {
        return intdiv($cents * $part * $bp + $whole * 5000, $whole * 10000);
    }

    /**
     * Splits $total across the keys of $weights in proportion to the weights.
     *
     * Each key first gets the whole cents of its share. The cents left over go
     * 1 each to the keys with the largest remainders, ties to the earlier key.
     * The parts always add up to $total exactly, unless every weight is 0.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0) {
            return array_map(fn () => 0, $weights);
        }
        $out = [];
        $rest = [];
        $given = 0;
        foreach ($weights as $k => $w) {
            $out[$k] = intdiv($total * $w, $sum);
            $rest[$k] = ($total * $w) % $sum;
            $given += $out[$k];
        }
        $keys = array_keys($weights);
        $order = array_keys($keys);
        usort($order, fn ($a, $b) => [$rest[$keys[$b]], $a] <=> [$rest[$keys[$a]], $b]);
        for ($n = 0; $n < $total - $given; $n++) {
            $out[$keys[$order[$n]]]++;
        }

        return $out;
    }

    public static function format(int $cents): string
    {
        return sprintf('$%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
