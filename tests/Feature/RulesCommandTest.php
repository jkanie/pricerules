<?php

namespace Tests\Feature;

use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RulesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function rule(array $over): Rule
    {
        return Rule::create($over + ['name' => 'Rule', 'type' => 'percent_off', 'params' => ['percent' => 10]]);
    }

    public function test_rules_list_shows_every_rule_by_priority_with_why_it_cannot_be_used(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->artisan('rules:list')->expectsOutput('There are no rules.')->assertSuccessful();
        $tea = $this->rule(['name' => 'Tea 10%', 'priority' => 5]);
        $off = $this->rule(['name' => 'Off', 'active' => false]);
        $soon = $this->rule(['name' => 'Soon', 'starts_at' => '2026-11-16 00:00:00']);
        $over = $this->rule(['name' => 'Over', 'ends_at' => '2026-11-15 12:00:00']);
        $spent = $this->rule(['name' => 'Spent', 'priority' => 9, 'coupon_code' => 'tea5', 'usage_limit' => 2]);
        $spent->forceFill(['times_used' => 2])->save();

        $this->artisan('rules:list')->expectsTable(['ID', 'Name', 'Type', 'Priority', 'Coupon', 'Uses left', 'Now'], [
            [$spent->id, 'Spent', 'percent_off', 9, 'TEA5', 0, 'used_up'],
            [$tea->id, 'Tea 10%', 'percent_off', 5, '', 'no limit', 'usable'],
            [$off->id, 'Off', 'percent_off', 0, '', 'no limit', 'off'],
            [$soon->id, 'Soon', 'percent_off', 0, '', 'no limit', 'not_started'],
            [$over->id, 'Over', 'percent_off', 0, '', 'no limit', 'ended'],
        ])->assertSuccessful();

        // --in-force keeps what GET /api/rules?in_force=1 keeps: a used-up coupon is still in force.
        $this->artisan('rules:list', ['--in-force' => true])->expectsTable(
            ['ID', 'Name', 'Type', 'Priority', 'Coupon', 'Uses left', 'Now'],
            [
                [$spent->id, 'Spent', 'percent_off', 9, 'TEA5', 0, 'used_up'],
                [$tea->id, 'Tea 10%', 'percent_off', 5, '', 'no limit', 'usable'],
            ],
        )->assertSuccessful();
        $this->assertSame(
            [$spent->id, $tea->id],
            array_column($this->getJson('/api/rules?in_force=1')->assertOk()->json(), 'id'),
        );
    }

    public function test_rules_list_in_force_says_when_none_is(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->rule(['name' => 'Off', 'active' => false]);
        $this->artisan('rules:list', ['--in-force' => true])
            ->expectsOutput('No rule matches these options.')->assertSuccessful();
    }

    public function test_rules_list_keeps_1_type_or_the_coupon_rules_as_the_api_does(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $tea = $this->rule(['name' => 'Tea 10%', 'priority' => 5]);
        $code = $this->rule(['name' => 'Code', 'coupon_code' => 'save5']);
        $cut = $this->rule(['name' => '$5 off', 'type' => 'amount_off', 'params' => ['amount_cents' => 500]]);
        $headers = ['ID', 'Name', 'Type', 'Priority', 'Coupon', 'Uses left', 'Now'];

        $this->artisan('rules:list', ['--type' => 'amount_off'])->expectsTable($headers, [
            [$cut->id, '$5 off', 'amount_off', 0, '', 'no limit', 'usable'],
        ])->assertSuccessful();
        $this->artisan('rules:list', ['--coupon' => true])->expectsTable($headers, [
            [$code->id, 'Code', 'percent_off', 0, 'SAVE5', 'no limit', 'usable'],
        ])->assertSuccessful();
        $this->artisan('rules:list', ['--type' => 'percent_off', '--coupon' => true, '--in-force' => true])
            ->expectsTable($headers, [[$code->id, 'Code', 'percent_off', 0, 'SAVE5', 'no limit', 'usable']])
            ->assertSuccessful();
        $this->artisan('rules:list', ['--type' => 'tiered'])
            ->expectsOutput('No rule matches these options.')->assertSuccessful();
        $this->artisan('rules:list', ['--type' => 'half_off'])
            ->expectsOutput('No rule type is called half_off. The types are '.implode(', ', Rule::TYPES).'.')
            ->assertExitCode(2);

        // The API's filters give the same rules.
        $ids = fn (string $q) => array_column($this->getJson("/api/rules?$q")->assertOk()->json(), 'id');
        $this->assertSame([$cut->id], $ids('type=amount_off'));
        $this->assertSame([$code->id], $ids('coupon=1'));
        $this->assertSame([$tea->id, $cut->id], $ids('coupon=0'));
    }

    public function test_rules_show_lists_every_field_of_1_rule(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $rule = $this->rule([
            'name' => 'Trade 5%', 'params' => ['percent' => 5], 'priority' => 3, 'exclusive' => true,
            'starts_at' => '2026-11-16 08:00:00', 'customer_groups' => ['trade', 'staff'],
            'coupon_code' => 'trade5', 'usage_limit' => 10,
        ]);
        $rule->forceFill(['times_used' => 4])->save();
        $this->artisan('rules:show', ['id' => $rule->id])->expectsTable(['Field', 'Value'], [
            ['ID', $rule->id],
            ['Name', 'Trade 5%'],
            ['Type', 'percent_off'],
            ['Params', '{"percent":5}'],
            ['Priority', 3],
            ['Exclusive', 'yes'],
            ['Active', 'yes'],
            ['Starts', '2026-11-16 08:00:00 UTC'],
            ['Ends', 'no end date'],
            ['Customer groups', 'trade, staff'],
            ['Coupon', 'TRADE5'],
            ['Uses', '4 of 10'],
            ['Now', 'not_started'],
        ])->assertSuccessful();

        $open = $this->rule(['name' => 'Open']);
        $this->artisan('rules:show', ['id' => $open->id])
            ->expectsOutputToContain('every group')
            ->expectsOutputToContain('0 (no limit)')
            ->expectsOutputToContain('usable')
            ->assertSuccessful();
        $this->artisan('rules:show', ['id' => 999])->expectsOutput('No rule has the id 999.')->assertExitCode(1);
    }
}
