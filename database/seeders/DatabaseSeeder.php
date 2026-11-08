<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Rule;
use Illuminate\Database\Seeder;

/** A small shop to try the API on: 4 products and 5 rules. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['sku' => 'MUG-01', 'name' => 'Stoneware mug', 'price_cents' => 1800, 'cost_cents' => 700, 'category' => 'kitchen'],
            ['sku' => 'TEA-01', 'name' => 'Green tea, 100 g', 'price_cents' => 1250, 'cost_cents' => 500, 'category' => 'pantry'],
            ['sku' => 'POT-01', 'name' => 'Cast iron teapot', 'price_cents' => 6400, 'cost_cents' => 3100, 'category' => 'kitchen'],
            ['sku' => 'CUP-01', 'name' => 'Paper cups, 50', 'price_cents' => 900, 'cost_cents' => null, 'category' => 'pantry'],
        ] as $p) {
            Product::create($p);
        }

        Rule::create(['name' => 'Tea: buy 2, get 1 free', 'type' => 'buy_x_get_y', 'priority' => 20,
            'params' => ['buy' => 2, 'get' => 1, 'skus' => ['TEA-01']]]);
        Rule::create(['name' => 'Teapot and mug for $80', 'type' => 'bundle', 'priority' => 15,
            'params' => ['skus' => ['POT-01', 'MUG-01'], 'price_cents' => 8000]]);
        Rule::create(['name' => 'Cups by the case', 'type' => 'tiered', 'priority' => 10,
            'params' => ['skus' => ['CUP-01'], 'tiers' => [['min_quantity' => 10, 'basis_points' => 1000], ['min_quantity' => 50, 'basis_points' => 2000]]]]);
        Rule::create(['name' => 'Trade 5%', 'type' => 'percent_off', 'priority' => 5,
            'params' => ['basis_points' => 500], 'customer_groups' => ['trade']]);
        Rule::create(['name' => 'WELCOME10: $10 off $50', 'type' => 'order_amount_off', 'priority' => 0,
            'params' => ['amount_cents' => 1000, 'min_subtotal_cents' => 5000], 'coupon_code' => 'WELCOME10', 'usage_limit' => 100]);
    }
}
