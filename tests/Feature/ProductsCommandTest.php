<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_list_shows_every_product_by_sku(): void
    {
        $this->artisan('products:list')->expectsOutput('There are no products.')->assertSuccessful();
        Product::create(['sku' => 'TEA-01', 'name' => 'Green tea', 'price_cents' => 1250, 'cost_cents' => 500, 'category' => 'pantry']);
        Product::create(['sku' => 'MUG-01', 'name' => 'Mug', 'price_cents' => 1800, 'cost_cents' => null, 'category' => 'kitchen']);
        Product::create(['sku' => 'BAG-01', 'name' => 'Gift bag', 'price_cents' => 0]);

        $this->artisan('products:list')->expectsTable(['SKU', 'Name', 'Category', 'Price (cents)', 'Cost (cents)'], [
            ['BAG-01', 'Gift bag', 'none', 0, 'not set'],
            ['MUG-01', 'Mug', 'kitchen', 1800, 'not set'],
            ['TEA-01', 'Green tea', 'pantry', 1250, 500],
        ])->assertSuccessful();
    }

    public function test_products_list_keeps_1_category_as_the_api_does(): void
    {
        foreach (['POT-01' => 'kitchen', 'MUG-01' => 'kitchen', 'TEA-01' => 'pantry', 'BAG-01' => null] as $sku => $category) {
            Product::create(['sku' => $sku, 'name' => $sku, 'price_cents' => 100, 'category' => $category]);
        }

        $this->artisan('products:list', ['--category' => 'kitchen'])->expectsTable(
            ['SKU', 'Name', 'Category', 'Price (cents)', 'Cost (cents)'],
            [['MUG-01', 'MUG-01', 'kitchen', 100, 'not set'], ['POT-01', 'POT-01', 'kitchen', 100, 'not set']],
        )->assertSuccessful();
        $this->assertSame(
            ['MUG-01', 'POT-01'],
            array_column($this->getJson('/api/products?category=kitchen')->assertOk()->json(), 'sku'),
        );
        // The match is exact, as in the API: a category in other case keeps nothing.
        $this->artisan('products:list', ['--category' => 'Kitchen'])
            ->expectsOutput('No product is in the category Kitchen.')->assertSuccessful();
        $this->assertSame([], $this->getJson('/api/products?category=Kitchen')->assertOk()->json());
    }
}
