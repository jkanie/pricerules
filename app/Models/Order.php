<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['customer_group', 'coupon_codes', 'subtotal_cents', 'discount_cents', 'total_cents', 'quote'];

    protected function casts(): array
    {
        return [
            'coupon_codes' => 'array',
            'quote' => 'array',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
