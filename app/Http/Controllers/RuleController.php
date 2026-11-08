<?php

namespace App\Http\Controllers;

use App\Http\Requests\RuleRequest;
use App\Models\Rule;

class RuleController extends Controller
{
    public function index()
    {
        return Rule::query()->orderByDesc('priority')->orderBy('id')->get();
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
