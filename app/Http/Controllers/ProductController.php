<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Lists the products by SKU. ?category=kitchen keeps 1 category, and ?category= (empty) keeps the products
     * with no category, the same products that a category rule never matches. ?q=mug keeps the products whose SKU
     * or name holds the text, ignoring the case of A to Z. % and _ in the text are plain characters.
     */
    public function index(Request $request)
    {
        $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $query = Product::query()->orderBy('sku');
        if ($request->has('category')) {
            $category = $request->query('category');
            $category === null || $category === '' ? $query->whereNull('category') : $query->where('category', $category);
        }
        $text = trim((string) $request->query('q', ''));
        if ($text !== '') {
            // LOWER() folds A to Z on SQLite and PostgreSQL alike, so the text is folded the same way here.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($text)).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(Product::create($data), 201);
    }

    public function show(Product $product)
    {
        return $product;
    }

    /**
     * Changes any of name, price, cost, category of 1 product. The SKU stays because carts and
     * bundle rules name products by SKU. Stored orders keep the prices they were priced with.
     */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'sku' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $product->update($data);

        return $product->refresh();
    }
}
