<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest;
use App\Models\Order;
use App\Services\QuoteService;
use DateTimeImmutable;
use DateTimeZone;

class QuoteController extends Controller
{
    public function __construct(private QuoteService $service) {}

    /** Prices a cart. Nothing is stored and no coupon use is spent. */
    public function quote(QuoteRequest $request)
    {
        $at = $request->filled('at')
            ? (new DateTimeImmutable($request->validated('at')))->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->service->quote(
            $request->items(),
            $request->validated('customer_group'),
            $request->validated('coupon_codes', []),
            $at,
        );
    }

    /** Prices a cart at the current time and stores it as an order. */
    public function order(QuoteRequest $request)
    {
        if ($request->filled('at')) {
            return response()->json(['message' => 'An order is always priced at the current time.'], 422);
        }
        [$order, $quote, $refused] = $this->service->order(
            $request->items(),
            $request->validated('customer_group'),
            $request->validated('coupon_codes', []),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($refused === 'unknown_code') {
            return response()->json(['message' => 'Unknown coupon code: '.implode(', ', $quote['unknown_coupon_codes']).'.', 'quote' => $quote], 422);
        }
        if ($refused === 'used_up') {
            return response()->json(['message' => 'A coupon code has no uses left.', 'quote' => $quote], 409);
        }

        return response()->json(['order_id' => $order->id] + $quote, 201);
    }

    public function show(Order $order)
    {
        return ['order_id' => $order->id, 'created_at' => $order->created_at->format(DATE_ATOM)] + $order->quote;
    }
}
