<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RuleController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::post('/products', [ProductController::class, 'store']);
Route::get('/rules', [RuleController::class, 'index']);
Route::post('/rules', [RuleController::class, 'store']);
Route::get('/rules/{rule}', [RuleController::class, 'show']);
Route::delete('/rules/{rule}', [RuleController::class, 'destroy']);
Route::post('/quotes', [QuoteController::class, 'quote']);
Route::post('/orders', [QuoteController::class, 'order']);
Route::get('/orders/{order}', [QuoteController::class, 'show']);
