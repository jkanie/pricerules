<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Product;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = ['sku' => 'CAP', 'name' => 'Cap', 'price_cents' => 900];

    private const RULE = ['name' => 'x', 'type' => 'amount_off', 'params' => ['amount_cents' => 1]];

    private const CART = ['items' => [['sku' => 'MUG', 'quantity' => 1]]];

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        Product::create(['sku' => 'MUG', 'name' => 'Mug', 'price_cents' => 1200]);
        $this->ruleId = Rule::create(self::RULE)->id;
    }

    public function test_changes_without_a_token_get_401(): void
    {
        $this->asToken(null);
        $this->postJson('/api/products', self::PRODUCT)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->postJson('/api/rules', self::RULE)->assertUnauthorized();
        $this->deleteJson("/api/rules/{$this->ruleId}")->assertUnauthorized();
        $this->postJson('/api/orders', self::CART)->assertUnauthorized();
        $this->asToken('1|not-a-real-token')->postJson('/api/products', self::PRODUCT)->assertUnauthorized();

        $this->assertSame(1, Product::count());
        $this->assertSame(1, Rule::count());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_reading_and_quoting_need_no_token(): void
    {
        $this->asToken(null);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/rules')->assertOk()->assertJsonCount(1);
        $this->getJson("/api/rules/{$this->ruleId}")->assertOk();
        $this->postJson('/api/quotes', self::CART)->assertOk()->assertJsonPath('total_cents', 1199);
    }

    public function test_a_shop_token_can_quote_and_order_but_not_change_anything(): void
    {
        $this->asToken($this->tokenFor(ApiClient::SHOP));
        $this->postJson('/api/products', self::PRODUCT)->assertForbidden()->assertJsonPath('message', 'Invalid ability provided.');
        $this->postJson('/api/rules', self::RULE)->assertForbidden();
        $this->deleteJson("/api/rules/{$this->ruleId}")->assertForbidden();
        $this->assertSame(1, Product::count());
        $this->assertSame(1, Rule::count());

        $this->postJson('/api/quotes', self::CART)->assertOk()->assertJsonPath('total_cents', 1199);
        $id = $this->postJson('/api/orders', self::CART)->assertCreated()->assertJsonPath('total_cents', 1199)->json('order_id');
        $this->getJson("/api/orders/{$id}")->assertOk();
    }

    public function test_a_staff_token_can_change_products_and_rules_and_order(): void
    {
        $this->asToken($this->tokenFor(ApiClient::STAFF));
        $this->postJson('/api/products', self::PRODUCT)->assertCreated();
        $id = $this->postJson('/api/rules', self::RULE)->assertCreated()->json('id');
        $this->deleteJson("/api/rules/{$id}")->assertNoContent();
        $this->deleteJson("/api/rules/{$this->ruleId}")->assertNoContent();
        $this->postJson('/api/orders', self::CART)->assertCreated()->assertJsonPath('total_cents', 1200);
        $this->assertSame(2, Product::count());
        $this->assertSame(0, Rule::count());
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $staff = $this->tokenFor(ApiClient::STAFF);
        $shop = $this->tokenFor(ApiClient::SHOP);
        $this->asToken($staff)->postJson('/api/rules', self::RULE)->assertCreated();
        $this->asToken($shop)->postJson('/api/orders', self::CART)->assertCreated();

        $this->artisan('token:revoke', ['id' => strtok($staff, '|')])->assertSuccessful();
        PersonalAccessToken::findToken($shop)->delete();

        $this->asToken($staff)->postJson('/api/rules', self::RULE)->assertUnauthorized();
        $this->asToken($shop)->postJson('/api/orders', self::CART)->assertUnauthorized();
        $this->assertSame(2, Rule::count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_the_commands_make_list_and_revoke_tokens(): void
    {
        $this->artisan('token:create', ['name' => 'till-1', '--role' => 'shop'])
            ->expectsOutputToContain('Token made for till-1 with the role shop.')->assertSuccessful();
        $token = PersonalAccessToken::sole();
        $this->assertSame(['shop'], $token->abilities);
        $this->assertSame('till-1', $token->tokenable->name);
        $this->artisan('token:create', ['name' => 'till-1', '--role' => 'shop'])->assertSuccessful();
        $this->assertSame(2, ApiClient::sole()->tokens()->count());

        $this->artisan('token:create', ['name' => 'till-1', '--role' => 'staff'])->assertFailed();
        $this->artisan('token:create', ['name' => 'ann'])->assertFailed();
        $this->artisan('token:create', ['name' => 'ann', '--role' => 'admin'])->assertFailed();
        $this->assertSame(2, PersonalAccessToken::count());

        $this->artisan('token:list')->expectsTable(['ID', 'Client', 'Role', 'Made', 'Last used'], [
            [$token->id, 'till-1', 'shop', $token->created_at->toDateTimeString(), 'never'],
            [$token->id + 1, 'till-1', 'shop', PersonalAccessToken::find($token->id + 1)->created_at->toDateTimeString(), 'never'],
        ])->assertSuccessful();

        $this->artisan('token:revoke', ['id' => $token->id])->expectsOutput("Token {$token->id} is revoked.")->assertSuccessful();
        $this->artisan('token:revoke', ['id' => $token->id])->assertFailed();
        $this->artisan('token:revoke', ['id' => 'abc'])->assertFailed();
        $this->assertSame(1, PersonalAccessToken::count());
    }
}
