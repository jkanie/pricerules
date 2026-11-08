<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Rule;
use App\Pricing\RuleSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReferencePricer;
use Tests\TestCase;
use Tests\Unit\EngineOracleTest;

/**
 * Stores 60 of the seeded random scenarios in the database, prices them
 * through POST /api/quotes and compares the answer with the reference
 * pricer, which proves the rules survive the trip through the database.
 */
class DatabaseOracleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_matches_the_reference_on_random_carts(): void
    {
        for ($seed = 1; $seed <= 60; $seed++) {
            Rule::query()->delete();
            Product::query()->delete();
            [$products, $cart, $rules, $at, $group, $codes] = EngineOracleTest::scenario($seed);
            foreach ($products as $p) {
                Product::create($p);
            }
            $specs = [];
            foreach ($rules as $r) {
                $m = new Rule([
                    'name' => $r->name, 'type' => $r->type, 'params' => $r->params, 'priority' => $r->priority,
                    'exclusive' => $r->exclusive, 'active' => $r->active, 'starts_at' => $r->startsAt,
                    'ends_at' => $r->endsAt, 'customer_groups' => $r->groups, 'coupon_code' => $r->couponCode,
                    'usage_limit' => $r->usageLimit,
                ]);
                $m->times_used = $r->timesUsed;
                $m->save();
                $specs[] = RuleSpec::fromModel($m->refresh());
            }
            $want = ReferencePricer::totals($products, $cart, $specs, $at, $group, $codes);
            $res = $this->postJson('/api/quotes', [
                'items' => $cart, 'customer_group' => $group, 'coupon_codes' => $codes, 'at' => $at->format(DATE_ATOM),
            ])->assertOk();
            $this->assertSame($want['total'], $res->json('total_cents'), "seed {$seed}");
            $this->assertSame(array_column($want['lines'], 'total'), array_column($res->json('lines'), 'total_cents'), "seed {$seed}");
        }
    }
}
