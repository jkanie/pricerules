<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A caller of the API, such as a till or a staff member, that holds API tokens.
 * Each token it gets carries its role as the token's only ability.
 */
class ApiClient extends Model
{
    use HasApiTokens;

    /** Staff can add products and add or remove rules. Staff can also place orders. */
    public const STAFF = 'staff';

    /** The shop front can place orders and nothing else. */
    public const SHOP = 'shop';

    public const ROLES = [self::STAFF, self::SHOP];

    protected $fillable = ['name', 'role'];

    /** Makes a new token for this client and returns it in plain text, the only time it can be read. */
    public function issueToken(): string
    {
        return $this->createToken($this->name, [$this->role])->plainTextToken;
    }
}
