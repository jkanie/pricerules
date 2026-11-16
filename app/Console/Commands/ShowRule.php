<?php

namespace App\Console\Commands;

use App\Models\Rule;
use Illuminate\Console\Command;

class ShowRule extends Command
{
    protected $signature = 'rules:show {id : The rule id, as rules:list shows it}';

    protected $description = 'Show every field of 1 pricing rule, with why it cannot be used now';

    public function handle(): int
    {
        $rule = Rule::find($this->argument('id'));
        if ($rule === null) {
            $this->error("No rule has the id {$this->argument('id')}.");

            return self::FAILURE;
        }
        $when = fn ($t) => $t === null ? null : $t->utc()->format('Y-m-d H:i:s').' UTC';
        $this->table(['Field', 'Value'], [
            ['ID', $rule->id],
            ['Name', $rule->name],
            ['Type', $rule->type],
            ['Params', json_encode($rule->params, JSON_UNESCAPED_SLASHES)],
            ['Priority', $rule->priority],
            ['Exclusive', $rule->exclusive ? 'yes' : 'no'],
            ['Active', $rule->active ? 'yes' : 'no'],
            ['Starts', $when($rule->starts_at) ?? 'no start date'],
            ['Ends', $when($rule->ends_at) ?? 'no end date'],
            ['Customer groups', $rule->customer_groups ? implode(', ', $rule->customer_groups) : 'every group'],
            ['Coupon', $rule->coupon_code ?? 'none'],
            ['Uses', $rule->usage_limit === null ? "{$rule->times_used} (no limit)" : "{$rule->times_used} of {$rule->usage_limit}"],
            ['Now', $rule->blockedReason(now()) ?? 'usable'],
        ]);

        return self::SUCCESS;
    }
}
