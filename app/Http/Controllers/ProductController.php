<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        return Product::query()->orderBy('sku')->get();
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
     * Changes the name, price, cost or category of 1 product. The SKU stays, because carts and
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
