<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RuleController;
use App\Models\ApiClient;
use Illuminate\Support\Facades\Route;

// Reading and quoting need no token.
Route::get('/products', [ProductController::class, 'index']);
Route::get('/rules', [RuleController::class, 'index']);
Route::get('/rules/{rule}', [RuleController::class, 'show']);
Route::post('/quotes', [QuoteController::class, 'quote']);
Route::get('/orders/{order}', [QuoteController::class, 'show']);

// Placing an order needs a shop or a staff token.
Route::middleware(['auth:sanctum', 'ability:'.ApiClient::SHOP.','.ApiClient::STAFF])->group(function () {
    Route::post('/orders', [QuoteController::class, 'order']);
});

// Changing products and rules needs a staff token.
Route::middleware(['auth:sanctum', 'abilities:'.ApiClient::STAFF])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/rules', [RuleController::class, 'store']);
    Route::delete('/rules/{rule}', [RuleController::class, 'destroy']);
});
