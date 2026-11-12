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

    public function destroy(Rule $rule)
    {
        $rule->delete();

        return response()->noContent();
    }
}
