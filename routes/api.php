<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MpesaController;

/*
|--------------------------------------------------------------------------
| API Routes - Shopify M-Pesa Integration
|--------------------------------------------------------------------------
|
| Simple 3-route setup:
| 1. Shopify webhook triggers payment
| 2. M-Pesa sends callback
| 3. Shopify checks status (optional)
|
*/

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Shopify M-Pesa Gateway',
        'timestamp' => now()->toIso8601String()
    ]);
});

// 1. SHOPIFY WEBHOOK → Triggers M-Pesa STK Push

Route::post('/payment/initiate/test', [MpesaController::class, 'stkPush']);

Route::post('/payment/initiate', [MpesaController::class, 'stkPush'])
    ->middleware('verify.shopify.webhook');

// 2. M-PESA CALLBACK → Updates payment status
Route::post('/payment/callback', [MpesaController::class, 'callback']);

// 3. STATUS CHECK → Shopify polls for payment result (optional)
Route::get('/payment/status/{orderId}', [MpesaController::class, 'checkStatus']);

// Test endpoint (development only)
Route::post('/payment/test', [MpesaController::class, 'stkPushTest'])
    ->middleware('throttle:5,1');