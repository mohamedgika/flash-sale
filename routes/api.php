<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WebhookController;

Route::group(['prefix' => 'v1'], function () {
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::post('holds', [HoldController::class, 'store']);
    Route::post('orders',[OrderController::class,'store']);
    Route::post('payments/webhook', [WebhookController::class, 'handle']);
});


