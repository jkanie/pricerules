<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Rule extends Model
{
    public const TYPES = ['percent_off', 'amount_off', 'buy_x_get_y', 'tiered', 'bundle', 'order_amount_off'];

    protected $fillable = [
        'name', 'type', 'params', 'priority', 'exclusive', 'active', 'starts_at',
        'ends_at', 'customer_groups', 'coupon_code', 'usage_limit',
    ];

    protected $attributes = ['times_used' => 0, 'priority' => 0, 'exclusive' => false, 'active' => true];

    protected $appends = ['uses_left'];

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

    /**
     * The coupon uses an order can still spend: usage_limit less times_used, never below 0. NULL when the rule has
     * no usage limit (only a rule with a coupon code can have 1).
     */
    protected function usesLeft(): Attribute
    {
        return Attribute::get(fn () => $this->usage_limit === null
            ? null
            : max(0, $this->usage_limit - $this->times_used));
    }

    /**
     * The rules that are turned on and inside their dates at $now: from starts_at, up to but not at ends_at. These
     * are the checks a quote makes before it looks at customer groups and coupon codes.
     */
    public function scopeInForce(Builder $query, DateTimeInterface $now): void
    {
        $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * Why the rule cannot be used at $now, as the 1st check that fails in the order a quote makes them: off,
     * not_started, ended, used_up. NULL when it can be used. The cart and the customer group are not checked.
     */
    public function blockedReason(DateTimeInterface $now): ?string
    {
        return match (true) {
            ! $this->active => 'off',
            $this->starts_at !== null && $this->starts_at > $now => 'not_started',
            $this->ends_at !== null && $this->ends_at <= $now => 'ended',
            $this->uses_left === 0 => 'used_up',
            default => null,
        };
    }

    /** Coupon codes are stored in capitals, which makes them match without regard to case. */
    public function setCouponCodeAttribute(?string $value): void
    {
        $this->attributes['coupon_code'] = $value === null ? null : strtoupper($value);
    }
}
