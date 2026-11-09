<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class CreateToken extends Command
{
    protected $signature = 'token:create
        {name : A name for the client that will hold the token, such as till-1}
        {--role= : staff (adds products, adds and removes rules) or shop (places orders)}';

    protected $description = 'Make an API token and print it once';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $role = (string) $this->option('role');
        if (! in_array($role, ApiClient::ROLES, true)) {
            $this->error('Give --role=staff or --role=shop.');

            return self::FAILURE;
        }
        if (strlen($name) > 64) {
            $this->error('The name can be at most 64 characters.');

            return self::FAILURE;
        }

        $client = ApiClient::query()->firstOrCreate(['name' => $name], ['role' => $role]);
        if ($client->role !== $role) {
            $this->error("The client {$name} already has the role {$client->role}. Use another name for a {$role} token.");

            return self::FAILURE;
        }

        $this->line($client->issueToken());
        $this->info("Token made for {$name} with the role {$role}. It is shown only now: store it in a safe place.");

        return self::SUCCESS;
    }
}
