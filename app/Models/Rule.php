<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rule extends Model
{
    public const TYPES = ['percent_off', 'amount_off', 'buy_x_get_y', 'tiered', 'bundle', 'order_amount_off'];

    protected $fillable = [
        'name', 'type', 'params', 'priority', 'exclusive', 'active', 'starts_at',
        'ends_at', 'customer_groups', 'coupon_code', 'usage_limit',
    ];

    protected $attributes = ['times_used' => 0, 'priority' => 0, 'exclusive' => false, 'active' => true];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'customer_groups' => 'array',
            'priority' => 'integer',
            'exclusive' => 'boolean',
            'active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'usage_limit' => 'integer',
            'times_used' => 'integer',
        ];
    }

    /** Coupon codes are stored in capitals, which makes them match without regard to case. */
    public function setCouponCodeAttribute(?string $value): void
    {
        $this->attributes['coupon_code'] = $value === null ? null : strtoupper($value);
    }
}
