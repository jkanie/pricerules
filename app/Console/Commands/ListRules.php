<?php

namespace App\Console\Commands;

use App\Models\Rule;
use Illuminate\Console\Command;

class ListRules extends Command
{
    protected $signature = 'rules:list
        {--in-force : List only the rules that are turned on and inside their dates now}';

    protected $description = 'List the pricing rules by priority, with why each one cannot be used now';

    public function handle(): int
    {
        $now = now();
        $query = Rule::query()->orderByDesc('priority')->orderBy('id');
        if ($this->option('in-force')) {
            $query->inForce($now);
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
            $this->info($this->option('in-force') ? 'No rule is in force now.' : 'There are no rules.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Name', 'Type', 'Priority', 'Coupon', 'Uses left', 'Now'], $rows);

        return self::SUCCESS;
    }
}
