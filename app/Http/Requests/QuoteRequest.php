<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku' => ['required', 'string', 'distinct', 'exists:products,sku'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'customer_group' => ['nullable', 'string', 'max:64'],
            'coupon_codes' => ['array', 'max:10'],
            'coupon_codes.*' => ['string', 'max:40'],
            'at' => ['nullable', 'date'],
        ];
    }

    /** @return list<array{sku: string, quantity: int}> */
    public function items(): array
    {
        return array_map(
            fn ($i) => ['sku' => (string) $i['sku'], 'quantity' => (int) $i['quantity']],
            array_values($this->validated('items')),
        );
    }
}
