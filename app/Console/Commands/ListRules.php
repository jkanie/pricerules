<?php

namespace App\Console\Commands;

use App\Models\Rule;
use Illuminate\Console\Command;

class ListRules extends Command
{
    protected $signature = 'rules:list
        {--in-force : List only the rules that are turned on and inside their dates now}
        {--type= : List only the rules of 1 type, such as percent_off}
        {--coupon : List only the rules that need a coupon code}';

    protected $description = 'List the pricing rules by priority, with why each one cannot be used now';

    public function handle(): int
    {
        $now = now();
        $query = Rule::query()->orderByDesc('priority')->orderBy('id');
        if ($this->option('in-force')) {
            $query->inForce($now);
        }
        $type = $this->option('type');
        if ($type !== null) {
            if (! in_array($type, Rule::TYPES, true)) {
                $this->error("No rule type is called {$type}. The types are ".implode(', ', Rule::TYPES).'.');

                return self::INVALID;
            }
            $query->where('type', $type);
        }
        if ($this->option('coupon')) {
            $query->needsCoupon();
        }
        $rows = $query->get()->map(fn (Rule $r) => [
            $r->id,
            $r->name,
            $r->type,
            $r->priority,
            $r->coupon_code ?? '',
            $r->uses_left ?? 'no limit',
            $r->blockedReason($now) ?? 'usable',
        ]);
        if ($rows->isEmpty()) {
            $filtered = $this->option('in-force') || $type !== null || $this->option('coupon');
            $this->info($filtered ? 'No rule matches these options.' : 'There are no rules.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Name', 'Type', 'Priority', 'Coupon', 'Uses left', 'Now'], $rows);

        return self::SUCCESS;
    }
}
