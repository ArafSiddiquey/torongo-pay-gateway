<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\SmsSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::post('/payments', [InvoiceController::class, 'store']);
    Route::get('/payments/{transaction:invoice_id}/status', [InvoiceController::class, 'status'])->middleware('throttle:120,1');
    Route::post('/sms/sync', [SmsSyncController::class, 'sync'])->middleware('throttle:120,1');
    Route::post('/sms/heartbeat', [SmsSyncController::class, 'heartbeat'])->middleware('throttle:180,1');
    Route::post('/sms/outgoing/fetch', [SmsSyncController::class, 'fetchOutgoing'])->middleware('throttle:120,1');
    Route::post('/sms/outgoing/report', [SmsSyncController::class, 'reportOutgoing'])->middleware('throttle:120,1');
});
