<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Product;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asToken($this->tokenFor(ApiClient::STAFF));
        $this->postJson('/api/products', ['sku' => 'MUG', 'name' => 'Mug', 'price_cents' => 1200, 'category' => 'kitchen'])->assertCreated();
        $this->postJson('/api/products', ['sku' => 'INK', 'name' => 'Ink', 'price_cents' => 1000, 'cost_cents' => 800])->assertCreated();
    }

    public function test_a_quote_explains_every_rule(): void
    {
        $this->postJson('/api/rules', ['name' => 'Kitchen 10%', 'type' => 'percent_off', 'priority' => 5,
            'params' => ['basis_points' => 1000, 'categories' => ['kitchen'], 'ignored' => 1]])->assertCreated()
            ->assertJsonPath('params', ['basis_points' => 1000, 'categories' => ['kitchen']]);
        $this->postJson('/api/rules', ['name' => 'Staff $1', 'type' => 'amount_off',
            'params' => ['amount_cents' => 100], 'customer_groups' => ['staff']])->assertCreated();

        $res = $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 2], ['sku' => 'INK', 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', 3400)
            ->assertJsonPath('discount_cents', 240)
            ->assertJsonPath('total_cents', 3160)
            ->assertJsonPath('rules.0.result', 'applied')
            ->assertJsonPath('rules.1.reason', 'the rule is for customer groups staff');
        $this->assertSame(240, $res->json('lines.0.discounts.0.amount_cents'));
    }

    public function test_a_quote_lists_unknown_coupon_codes_and_spends_nothing(): void
    {
        $this->postJson('/api/rules', ['name' => 'Spring', 'type' => 'amount_off', 'params' => ['amount_cents' => 100],
            'coupon_code' => 'spring', 'usage_limit' => 1])->assertCreated()->assertJsonPath('coupon_code', 'SPRING');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 1]], 'coupon_codes' => ['Spring', 'NOPE']])
                ->assertOk()->assertJsonPath('discount_cents', 100)->assertJsonPath('unknown_coupon_codes', ['NOPE']);
        }
        $this->assertSame(0, Rule::first()->times_used);
    }

    public function test_an_order_spends_a_coupon_use_and_the_last_use_runs_out(): void
    {
        $this->postJson('/api/rules', ['name' => 'Spring', 'type' => 'amount_off', 'params' => ['amount_cents' => 100],
            'coupon_code' => 'SPRING', 'usage_limit' => 1])->assertCreated();
        $cart = ['items' => [['sku' => 'MUG', 'quantity' => 1]], 'coupon_codes' => ['spring']];

        $id = $this->postJson('/api/orders', $cart)->assertCreated()->assertJsonPath('total_cents', 1100)->json('order_id');
        $this->assertSame(1, Rule::first()->times_used);
        $this->postJson('/api/orders', $cart)->assertStatus(409)
            ->assertJsonPath('quote.rules.0.reason', 'coupon code SPRING has no uses left');
        $this->assertSame(1, Rule::first()->times_used);
        $this->getJson("/api/orders/{$id}")->assertOk()->assertJsonPath('total_cents', 1100);
    }

    public function test_an_order_refuses_an_unknown_coupon_and_a_set_time(): void
    {
        $this->postJson('/api/orders', ['items' => [['sku' => 'MUG', 'quantity' => 1]], 'coupon_codes' => ['NOPE']])
            ->assertStatus(422)->assertJsonPath('message', 'Unknown coupon code: NOPE.');
        $this->postJson('/api/orders', ['items' => [['sku' => 'MUG', 'quantity' => 1]], 'at' => '2030-01-01T00:00:00Z'])
            ->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_quote_can_be_priced_at_another_time(): void
    {
        $this->postJson('/api/rules', ['name' => 'Boxing Day', 'type' => 'percent_off', 'params' => ['basis_points' => 2500],
            'starts_at' => '2026-12-26T00:00:00Z', 'ends_at' => '2026-12-27T00:00:00Z'])->assertCreated();
        $cart = ['items' => [['sku' => 'MUG', 'quantity' => 1]]];
        $this->postJson('/api/quotes', $cart + ['at' => '2026-12-26T08:00:00-08:00'])->assertJsonPath('discount_cents', 300);
        $this->postJson('/api/quotes', $cart + ['at' => '2026-12-27T00:00:00Z'])->assertJsonPath('discount_cents', 0)
            ->assertJsonPath('rules.0.reason', 'the rule ended at 2026-12-27T00:00:00+00:00');
    }

    public function test_bad_carts_are_refused(): void
    {
        $this->postJson('/api/quotes', ['items' => []])->assertStatus(422);
        $this->postJson('/api/quotes', ['items' => [['sku' => 'NOPE', 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('items.0.sku');
        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 1], ['sku' => 'MUG', 'quantity' => 2]]])->assertStatus(422);
        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 0]]])->assertStatus(422);
        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 1.5]]])->assertStatus(422);
    }

    public function test_bad_rules_are_refused(): void
    {
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'percent_off', 'params' => ['basis_points' => 10001]])
            ->assertJsonValidationErrors('params.basis_points');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'magic', 'params' => []])->assertJsonValidationErrors('type');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['skus' => ['MUG'], 'price_cents' => 1]])
            ->assertJsonValidationErrors('params.skus');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['items' => [['sku' => 'MUG', 'quantity' => 1]], 'price_cents' => 1]])
            ->assertJsonValidationErrors('params.items');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['items' => [['sku' => 'MUG', 'quantity' => 0], ['sku' => 'INK', 'quantity' => 1]], 'price_cents' => 1]])
            ->assertJsonValidationErrors('params.items.0.quantity');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['items' => [['sku' => 'MUG', 'quantity' => 1], ['sku' => 'MUG', 'quantity' => 1]], 'price_cents' => 1]])
            ->assertJsonValidationErrors('params.items.0.sku');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['skus' => ['MUG', 'INK'], 'items' => [['sku' => 'MUG', 'quantity' => 2]], 'price_cents' => 1]])
            ->assertJsonValidationErrors('params.skus');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'bundle', 'params' => ['price_cents' => 1]])
            ->assertJsonValidationErrors(['params.skus', 'params.items']);
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'amount_off', 'params' => ['amount_cents' => 1], 'usage_limit' => 3])
            ->assertJsonValidationErrors('usage_limit');
        $this->postJson('/api/rules', ['name' => 'x', 'type' => 'amount_off', 'params' => ['amount_cents' => 1],
            'starts_at' => '2026-02-01', 'ends_at' => '2026-01-01'])->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/rules', ['name' => 'a', 'type' => 'amount_off', 'params' => ['amount_cents' => 1], 'coupon_code' => 'SAVE'])->assertCreated();
        $this->postJson('/api/rules', ['name' => 'b', 'type' => 'amount_off', 'params' => ['amount_cents' => 1], 'coupon_code' => 'save'])
            ->assertJsonValidationErrors('coupon_code');
    }

    public function test_a_bundle_with_quantities_is_stored_and_priced(): void
    {
        $this->postJson('/api/rules', ['name' => '2 mugs and ink for $30', 'type' => 'bundle',
            'params' => ['items' => [['sku' => 'MUG', 'quantity' => 2], ['sku' => 'INK', 'quantity' => 1]], 'price_cents' => 3000]])
            ->assertCreated()
            ->assertJsonPath('params.items', [['sku' => 'MUG', 'quantity' => 2], ['sku' => 'INK', 'quantity' => 1]])
            ->assertJsonPath('params.price_cents', 3000)
            ->assertJsonMissingPath('params.skus');

        // 1 set: 2 mugs (2400) and 1 ink (1000) for 3000 is 400 off. The 3rd mug keeps its price.
        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 3], ['sku' => 'INK', 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('discount_cents', 400)
            ->assertJsonPath('rules.0.reason', '1 bundle(s)');
        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 1], ['sku' => 'INK', 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('discount_cents', 0)
            ->assertJsonPath('rules.0.reason', 'the cart has too few of MUG (1 of 2)');
    }

    public function test_a_product_can_be_read_and_changed_by_sku(): void
    {
        $id = $this->postJson('/api/orders', ['items' => [['sku' => 'MUG', 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('total_cents', 1200)->json('order_id');

        $this->getJson('/api/products/MUG')->assertOk()->assertJsonPath('price_cents', 1200);
        $this->patchJson('/api/products/MUG', ['price_cents' => 1500, 'cost_cents' => 900])->assertOk()
            ->assertJsonPath('sku', 'MUG')->assertJsonPath('name', 'Mug')->assertJsonPath('price_cents', 1500)
            ->assertJsonPath('cost_cents', 900)->assertJsonPath('category', 'kitchen');
        $this->patchJson('/api/products/MUG', ['category' => null])->assertOk()->assertJsonPath('category', null)
            ->assertJsonPath('price_cents', 1500);

        $this->postJson('/api/quotes', ['items' => [['sku' => 'MUG', 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('subtotal_cents', 3000);
        // The stored order keeps the price it was priced with.
        $this->getJson("/api/orders/{$id}")->assertOk()->assertJsonPath('total_cents', 1200)
            ->assertJsonPath('subtotal_cents', 1200)->assertJsonPath('lines.0.unit_price_cents', 1200);
    }

    public function test_products_can_be_found_by_text_in_the_sku_or_name(): void
    {
        foreach ([['TEA-01', 'Green tea 100%'], ['TEA-02', 'Black tea'], ['CUP_9', 'Tea cup'], ['PLATE', 'Plate']] as [$sku, $name]) {
            $this->postJson('/api/products', ['sku' => $sku, 'name' => $name, 'price_cents' => 300])->assertCreated();
        }

        $skus = fn (string $url) => array_column($this->getJson($url)->assertOk()->json(), 'sku');
        // The SKU or the name, in any case of A to Z, in SKU order.
        $this->assertSame(['CUP_9', 'TEA-01', 'TEA-02'], $skus('/api/products?q=tea'));
        $this->assertSame(['CUP_9', 'TEA-01', 'TEA-02'], $skus('/api/products?q=TeA'));
        $this->assertSame(['TEA-02'], $skus('/api/products?q=ea-02'));
        // % and _ are plain characters, not wild-cards.
        $this->assertSame(['TEA-01'], $skus('/api/products?q=100%25'));
        $this->assertSame(['CUP_9'], $skus('/api/products?q=p_'));
        $this->assertSame([], $skus('/api/products?q=%25%25'));
        // Blank text keeps every product, and q works with category.
        $this->assertCount(6, $skus('/api/products?q=%20%20'));
        $this->assertSame(['MUG'], $skus('/api/products?q=mug&category=kitchen'));
        $this->getJson('/api/products?q[]=tea')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_products_can_be_listed_by_price_range(): void
    {
        foreach ([['P-499', 499], ['P-500', 500], ['P-2000', 2000], ['P-2001', 2001]] as [$sku, $cents]) {
            $this->postJson('/api/products', ['sku' => $sku, 'name' => $sku, 'price_cents' => $cents])->assertCreated();
        }
        $skus = fn (string $url) => array_column($this->getJson($url)->assertOk()->json(), 'sku');

        // Both ends are included.
        $this->assertSame(['P-2000', 'P-500'], $skus('/api/products?min_price_cents=500&max_price_cents=2000&q=p-'));
        $this->assertSame(['P-2000', 'P-2001'], $skus('/api/products?min_price_cents=2000&q=p-'));
        $this->assertSame(['P-499', 'P-500'], $skus('/api/products?max_price_cents=500&q=p-'));
        $this->assertSame(['P-500'], $skus('/api/products?min_price_cents=500&max_price_cents=500&q=p-'));
        $this->getJson('/api/products?min_price_cents=-1')->assertUnprocessable()->assertJsonValidationErrors('min_price_cents');
        $this->getJson('/api/products?max_price_cents=1.5')->assertUnprocessable()->assertJsonValidationErrors('max_price_cents');
        $this->getJson('/api/products?min_price_cents=600&max_price_cents=500')
            ->assertUnprocessable()->assertJsonValidationErrors('max_price_cents');
    }

    public function test_products_can_be_sorted_by_price_or_sku(): void
    {
        foreach ([['S-B', 700], ['S-A', 700], ['S-C', 300], ['S-D', 900]] as [$sku, $cents]) {
            $this->postJson('/api/products', ['sku' => $sku, 'name' => $sku, 'price_cents' => $cents])->assertCreated();
        }
        $skus = fn (string $sort) => array_column($this->getJson('/api/products?q=s-'.$sort)->assertOk()->json(), 'sku');

        // Equal prices keep SKU order both ways.
        $this->assertSame(['S-C', 'S-A', 'S-B', 'S-D'], $skus('&sort=price_cents'));
        $this->assertSame(['S-D', 'S-A', 'S-B', 'S-C'], $skus('&sort=-price_cents'));
        $this->assertSame(['S-A', 'S-B', 'S-C', 'S-D'], $skus(''));
        $this->assertSame(['S-A', 'S-B', 'S-C', 'S-D'], $skus('&sort=sku'));
        $this->assertSame(['S-D', 'S-C', 'S-B', 'S-A'], $skus('&sort=-sku'));
        $this->getJson('/api/products?sort=name')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/products?sort[]=sku')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_products_can_be_listed_by_category(): void
    {
        $this->postJson('/api/products', ['sku' => 'BAG', 'name' => 'Bag', 'price_cents' => 500])->assertCreated();
        $this->postJson('/api/products', ['sku' => 'BOWL', 'name' => 'Bowl', 'price_cents' => 900, 'category' => 'kitchen'])->assertCreated();

        $skus = fn (string $url) => array_column($this->getJson($url)->assertOk()->json(), 'sku');
        $this->assertSame(['BAG', 'BOWL', 'INK', 'MUG'], $skus('/api/products'));
        $this->assertSame(['BOWL', 'MUG'], $skus('/api/products?category=kitchen'));
        $this->assertSame([], $skus('/api/products?category=pantry'));
        $this->assertSame([], $skus('/api/products?category=Kitchen'));
        $this->assertSame(['BAG', 'INK'], $skus('/api/products?category='));
        $this->getJson('/api/products?category[]=kitchen')->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_rules_can_be_listed_by_type_and_by_whether_they_are_in_force(): void
    {
        $this->travelTo('2027-03-10 12:00:00');
        $add = fn (string $name, string $type, array $params, array $extra = []) => $this->postJson('/api/rules',
            ['name' => $name, 'type' => $type, 'params' => $params] + $extra)->assertCreated();
        $off = ['amount_cents' => 100];
        $add('Plain', 'amount_off', $off);
        $add('Ten', 'percent_off', ['basis_points' => 1000], ['priority' => 9]);
        $add('Off', 'amount_off', $off, ['active' => false]);
        $add('Later', 'amount_off', $off, ['starts_at' => '2027-03-11T00:00:00Z']);
        $add('Started', 'amount_off', $off, ['starts_at' => '2027-03-10T12:00:00Z']);
        $add('Ended', 'amount_off', $off, ['starts_at' => '2027-03-01T00:00:00Z', 'ends_at' => '2027-03-10T12:00:00Z']);
        $add('Running', 'amount_off', $off, ['ends_at' => '2027-03-10T12:00:01Z']);

        $names = fn (string $url) => array_column($this->getJson($url)->assertOk()->json(), 'name');
        $this->assertSame(['Ten', 'Plain', 'Off', 'Later', 'Started', 'Ended', 'Running'], $names('/api/rules'));
        $this->assertSame(['Ten'], $names('/api/rules?type=percent_off'));
        $this->assertSame([], $names('/api/rules?type=bundle'));
        // A rule starts at its starts_at and has ended at its ends_at, as in a quote.
        $this->assertSame(['Ten', 'Plain', 'Started', 'Running'], $names('/api/rules?in_force=1'));
        $this->assertSame(['Off', 'Later', 'Ended'], $names('/api/rules?in_force=0'));
        $this->assertSame(['Plain', 'Started', 'Running'], $names('/api/rules?type=amount_off&in_force=1'));
        $this->getJson('/api/rules?type=free')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/rules?in_force=maybe')->assertUnprocessable()->assertJsonValidationErrors('in_force');
    }

    public function test_rules_can_be_listed_by_whether_they_need_a_coupon(): void
    {
        $add = fn (string $name, array $extra = []) => $this->postJson('/api/rules',
            ['name' => $name, 'type' => 'amount_off', 'params' => ['amount_cents' => 100]] + $extra)->assertCreated();
        $add('Plain');
        $add('Spring', ['coupon_code' => 'SPRING10']);
        $add('Staff', ['coupon_code' => 'STAFF', 'priority' => 5]);

        $names = fn (string $url) => array_column($this->getJson($url)->assertOk()->json(), 'name');
        $this->assertSame(['Staff', 'Spring'], $names('/api/rules?coupon=1'));
        $this->assertSame(['Plain'], $names('/api/rules?coupon=0'));
        $this->assertSame(['Staff', 'Plain', 'Spring'], $names('/api/rules'));
        $this->assertSame(['Staff', 'Spring'], $names('/api/rules?coupon=1&type=amount_off'));
        $this->getJson('/api/rules?coupon=yes')->assertUnprocessable()->assertJsonValidationErrors('coupon');
    }

    public function test_bad_product_changes_are_refused(): void
    {
        $this->getJson('/api/products/NOPE')->assertNotFound();
        $this->patchJson('/api/products/NOPE', ['price_cents' => 1])->assertNotFound();
        $this->patchJson('/api/products/MUG', ['sku' => 'CUP'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->patchJson('/api/products/MUG', ['price_cents' => -1])->assertUnprocessable()->assertJsonValidationErrors('price_cents');
        $this->patchJson('/api/products/MUG', ['price_cents' => null])->assertUnprocessable()->assertJsonValidationErrors('price_cents');
        $this->patchJson('/api/products/MUG', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson('/api/products/MUG')->assertJsonPath('price_cents', 1200)->assertJsonPath('name', 'Mug');
    }

    public function test_rules_and_products_can_be_listed_and_removed(): void
    {
        $id = $this->postJson('/api/rules', ['name' => 'x', 'type' => 'amount_off', 'params' => ['amount_cents' => 1]])->json('id');
        $this->getJson('/api/products')->assertOk()->assertJsonCount(2)->assertJsonPath('0.sku', 'INK');
        $this->getJson("/api/rules/{$id}")->assertOk()->assertJsonPath('name', 'x');
        $this->deleteJson("/api/rules/{$id}")->assertNoContent();
        $this->getJson('/api/rules')->assertOk()->assertJsonCount(0);
        $this->assertSame(2, Product::count());
    }
}
