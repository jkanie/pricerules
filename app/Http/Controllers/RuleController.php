<?php

namespace App\Http\Controllers;

use App\Http\Requests\RuleRequest;
use App\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as Valid;

class RuleController extends Controller
{
    /**
     * Lists the rules by priority. ?type=percent_off keeps 1 type. ?in_force=1 keeps the rules that are turned on
     * and inside their dates now (from starts_at, up to but not at ends_at), the same checks that a quote makes
     * before it looks at customer groups and coupon codes. ?in_force=0 keeps the rest.
     */
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['sometimes', 'string', Valid::in(Rule::TYPES)],
            'in_force' => ['sometimes', 'boolean'],
            'coupon' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $query = Rule::query()->orderByDesc('priority')->orderBy('id');
        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->has('in_force')) {
            $now = now();
            $inForce = fn ($q) => $q->where('active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
            $request->boolean('in_force') ? $query->where($inForce) : $query->whereNot($inForce);
        }
        if ($request->has('coupon')) {
            // ?coupon=1 keeps the rules that need a coupon code, ?coupon=0 the rules that apply without 1.
            $request->boolean('coupon') ? $query->whereNotNull('coupon_code') : $query->whereNull('coupon_code');
        }
        $text = trim((string) $request->query('q', ''));
        if ($text !== '') {
            // The same match as the product list: part of the name, A to Z folded, with % and _ taken as written.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($text)).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]);
        }

        return $query->get();
    }

    public function store(RuleRequest $request)
    {
        $data = $request->safe()->except('params');
        $data['params'] = $request->params();

        return response()->json(Rule::create($data)->refresh(), 201);
    }

    public function show(Rule $rule)
    {
        return $rule;
    }

    /**
     * Whether a coupon code can be used now, such as for a checkout that checks a code as it is typed. The code is
     * read in any case, as a quote reads it. reason is null when the code is usable, else the 1st check that fails,
     * in the order a quote makes them: off, not_started, ended, used_up. The cart and the customer group are not
     * checked here: a quote still decides whether the rule applies to them.
     */
    public function coupon(string $code)
    {
        $rule = Rule::where('coupon_code', strtoupper($code))->first();
        if ($rule === null) {
            return response()->json(['message' => "No rule has the coupon code {$code}."], 404);
        }
        $now = now();
        $reason = match (true) {
            ! $rule->active => 'off',
            $rule->starts_at !== null && $rule->starts_at->greaterThan($now) => 'not_started',
            $rule->ends_at !== null && $rule->ends_at->lessThanOrEqualTo($now) => 'ended',
            $rule->uses_left === 0 => 'used_up',
            default => null,
        };

        return ['code' => $rule->coupon_code, 'usable' => $reason === null, 'reason' => $reason, 'rule' => $rule];
    }

    public function destroy(Rule $rule)
    {
        $rule->delete();

        return response()->noContent();
    }
}
