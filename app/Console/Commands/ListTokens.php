<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class ListTokens extends Command
{
    protected $signature = 'token:list';

    protected $description = 'List the API tokens without their secrets';

    public function handle(): int
    {
        $rows = PersonalAccessToken::query()->with('tokenable')->orderBy('id')->get()
            ->map(fn (PersonalAccessToken $t) => [
                $t->id,
                $t->tokenable?->name,
                implode(', ', $t->abilities),
                $t->created_at?->toDateTimeString(),
                $t->last_used_at?->toDateTimeString() ?? 'never',
            ]);

        $this->table(['ID', 'Client', 'Role', 'Made', 'Last used'], $rows);

        return self::SUCCESS;
    }
}
