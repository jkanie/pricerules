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
}
