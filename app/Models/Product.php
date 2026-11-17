<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['sku', 'name', 'price_cents', 'cost_cents', 'category'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'cost_cents' => 'integer'];
    }

    /**
     * The products whose SKU or name holds the text, ignoring the case of A to Z, as ?q= of GET /api/products and
     * --search of products:list keep them. % and _ in the text are plain characters. LOWER() folds A to Z on SQLite and
     * PostgreSQL alike, so the text is folded the same way here.
     */
    public function scopeMatching(Builder $query, string $text): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($text)).'%';
        $query->where(fn ($q) => $q->whereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$like])
            ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]));
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
