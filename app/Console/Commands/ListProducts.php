<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ListProducts extends Command
{
    protected $signature = 'products:list
        {--category= : List only the products of 1 category, such as kitchen}
        {--search= : List only the products whose SKU or name holds the text, such as mug}';

    protected $description = 'List the products by SKU, with their list price and cost in cents';

    public function handle(): int
    {
        // The same order and category match as GET /api/products: by SKU, the category matched exactly.
        $query = Product::query()->orderBy('sku');
        $category = $this->option('category');
        if ($category !== null) {
            $query->where('category', $category);
        }
        $text = trim((string) $this->option('search'));
        if ($text !== '') {
            $query->matching($text);
        }
        $rows = $query->get()->map(fn (Product $p) => [
            $p->sku,
            $p->name,
            $p->category ?? 'none',
            $p->price_cents,
            $p->cost_cents ?? 'not set',
        ]);
        if ($rows->isEmpty()) {
            $this->info(match (true) {
                $text !== '' => "No product has \"{$text}\" in its SKU or name".($category === null ? '.' : " in the category {$category}."),
                $category !== null => "No product is in the category {$category}.",
                default => 'There are no products.',
            });

            return self::SUCCESS;
        }
        $this->table(['SKU', 'Name', 'Category', 'Price (cents)', 'Cost (cents)'], $rows);

        return self::SUCCESS;
    }
}
