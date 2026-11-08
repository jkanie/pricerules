<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['sku', 'name', 'price_cents', 'cost_cents', 'category'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'cost_cents' => 'integer'];
    }

    /** @return array{sku: string, name: string, price_cents: int, cost_cents: ?int, category: ?string} */
    public function forEngine(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'price_cents' => $this->price_cents,
            'cost_cents' => $this->cost_cents,
            'category' => $this->category,
        ];
    }
}
