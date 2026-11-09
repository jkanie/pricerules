<?php

namespace Tests;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Makes a client with the role and returns a new plain-text token for it. */
    protected function tokenFor(string $role, ?string $name = null): string
    {
        return ApiClient::create(['name' => $name ?? $role.'-'.ApiClient::count(), 'role' => $role])->issueToken();
    }

    /**
     * Sends the next requests with this token, or with none when it is null.
     * The auth guard keeps the client it found for the whole test, so it is
     * cleared first, as a fresh request in a running app would find it.
     */
    protected function asToken(?string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token === null ? $this : $this->withToken($token);
    }
}
