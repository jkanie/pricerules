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
     * ?min_price_cents=500&max_price_cents=2000 keeps the products whose list price is in that range, both ends
     * included.
     */
    public function index(Request $request)
    {
        $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'min_price_cents' => ['sometimes', 'integer', 'min:0'],
            // gte refuses a field whose partner is missing, so it applies only when both are given.
            'max_price_cents' => ['sometimes', 'integer', 'min:0', ...($request->has('min_price_cents') ? ['gte:min_price_cents'] : [])],
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

        if ($request->has('min_price_cents')) {
            $query->where('price_cents', '>=', (int) $request->query('min_price_cents'));
        }
        if ($request->has('max_price_cents')) {
            $query->where('price_cents', '<=', (int) $request->query('max_price_cents'));
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
