<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeToken extends Command
{
    protected $signature = 'token:revoke {id : The token ID, as token:list shows it}';

    protected $description = 'Revoke an API token, which stops it working at once';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $token = ctype_digit($id) ? PersonalAccessToken::query()->find((int) $id) : null;
        if ($token === null) {
            $this->error("There is no token with the ID {$this->argument('id')}.");

            return self::FAILURE;
        }

        $token->delete();
        $this->info("Token {$token->id} is revoked.");

        return self::SUCCESS;
    }
}
