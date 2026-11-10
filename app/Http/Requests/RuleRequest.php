<?php

namespace App\Http\Requests;

use App\Models\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as V;

class RuleRequest extends FormRequest
{
    public function rules(): array
    {
        $bp = ['integer', 'min:1', 'max:10000'];
        $targets = [
            'params.skus' => ['sometimes', 'array'],
            'params.skus.*' => ['string', 'distinct', 'exists:products,sku'],
            'params.categories' => ['sometimes', 'array'],
            'params.categories.*' => ['string', 'distinct'],
        ];

        $byType = match ($this->input('type')) {
            'percent_off' => ['params.basis_points' => ['required', ...$bp]] + $targets,
            'amount_off' => [
                'params.amount_cents' => ['required', 'integer', 'min:1'],
                'params.per' => ['sometimes', V::in(['unit', 'line'])],
            ] + $targets,
            'buy_x_get_y' => [
                'params.buy' => ['required', 'integer', 'min:1', 'max:1000'],
                'params.get' => ['required', 'integer', 'min:1', 'max:1000'],
                'params.basis_points' => ['sometimes', ...$bp],
            ] + $targets,
            'tiered' => [
                'params.tiers' => ['required', 'array', 'min:1', 'max:20'],
                'params.tiers.*.min_quantity' => ['required', 'integer', 'min:1'],
                'params.tiers.*.basis_points' => ['required', ...$bp],
            ] + $targets,
            'bundle' => [
                'params.skus' => ['required_without:params.items', 'prohibits:params.items', 'array', 'min:2', 'max:20'],
                'params.skus.*' => ['string', 'distinct', 'exists:products,sku'],
                'params.items' => ['required_without:params.skus', 'array', 'min:1', 'max:20'],
                'params.items.*' => ['array:sku,quantity'],
                'params.items.*.sku' => ['required', 'string', 'distinct', 'exists:products,sku'],
                'params.items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
                'params.price_cents' => ['required', 'integer', 'min:0'],
            ],
            'order_amount_off' => [
                'params.amount_cents' => ['required', 'integer', 'min:1'],
                'params.min_subtotal_cents' => ['sometimes', 'integer', 'min:0'],
            ],
            default => [],
        };

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', V::in(Rule::TYPES)],
            'params' => ['required', 'array'],
            'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'exclusive' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'customer_groups' => ['nullable', 'array', 'min:1'],
            'customer_groups.*' => ['string', 'distinct', 'max:64'],
            'coupon_code' => ['nullable', 'string', 'alpha_dash', 'max:40'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
        ] + $byType;
    }

    public function after(): array
    {
        return [function ($validator) {
            $code = $this->input('coupon_code');
            if ($this->filled('usage_limit') && ! $code) {
                $validator->errors()->add('usage_limit', 'A usage limit needs a coupon code.');
            }
            $items = $this->input('params.items');
            if ($this->input('type') === 'bundle' && is_array($items)
                && array_sum(array_map(fn ($i) => is_array($i) && is_int($i['quantity'] ?? null) ? $i['quantity'] : 0, $items)) < 2) {
                $validator->errors()->add('params.items', 'A bundle needs at least 2 units in total.');
            }
            if ($code && Rule::query()->where('coupon_code', strtoupper($code))->exists()) {
                $validator->errors()->add('coupon_code', 'That coupon code is already in use.');
            }
        }];
    }

    /** Only the keys that each type reads are stored. */
    public function params(): array
    {
        $keep = [
            'percent_off' => ['basis_points', 'skus', 'categories'],
            'amount_off' => ['amount_cents', 'per', 'skus', 'categories'],
            'buy_x_get_y' => ['buy', 'get', 'basis_points', 'skus', 'categories'],
            'tiered' => ['tiers', 'skus', 'categories'],
            'bundle' => ['skus', 'items', 'price_cents'],
            'order_amount_off' => ['amount_cents', 'min_subtotal_cents'],
        ][$this->validated('type')];

        return array_intersect_key($this->validated('params'), array_flip($keep));
    }
}
