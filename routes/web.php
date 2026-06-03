<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Payment\GatewayController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');
Route::get('/invoice-{transaction:invoice_id}', [GatewayController::class, 'show'])->name('payment.invoice');
Route::get('/invoice-{transaction:invoice_id}/download', [GatewayController::class, 'downloadPdf'])->name('payment.invoice.download');
Route::get('/pay/{transaction:invoice_id}', [GatewayController::class, 'show'])->name('payment.show');
Route::post('/pay/{transaction:invoice_id}/sender', [GatewayController::class, 'saveSender'])->name('payment.sender');
Route::get('/pay/{transaction:invoice_id}/instructions', [GatewayController::class, 'instructions'])->name('payment.instructions');
Route::get('/pay/{transaction:invoice_id}/processing', [GatewayController::class, 'processing'])->name('payment.processing');
Route::get('/pay/{transaction:invoice_id}/status', [GatewayController::class, 'status'])->name('payment.status');
Route::post('/pay/{transaction:invoice_id}/hold', [GatewayController::class, 'holdManual'])->name('payment.hold');
Route::post('/pay/{transaction:invoice_id}/manual', [GatewayController::class, 'manualVerify'])->name('payment.manual');
Route::post('/pay/{transaction:invoice_id}/remittance-contact', [GatewayController::class, 'remittanceContact'])->name('payment.remittance.contact');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/invoices', [AdminController::class, 'invoices'])->name('invoices');
        Route::post('/invoices', [AdminController::class, 'storeInvoice'])->name('invoices.store');
        Route::post('/invoices/{transaction}/discount-due', [AdminController::class, 'discountDue'])->name('invoices.discount_due');
        Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
        Route::post('/transactions/reject-all-pending', [AdminController::class, 'rejectAllPending'])->name('transactions.reject_all_pending');
        Route::post('/transactions/{transaction}/approve', [AdminController::class, 'approve'])->name('transactions.approve');
        Route::post('/transactions/{transaction}/reject', [AdminController::class, 'reject'])->name('transactions.reject');
        Route::resource('/methods', \App\Http\Controllers\Admin\MethodController::class)->except(['show']);
        Route::get('/devices/status', [\App\Http\Controllers\Admin\DeviceController::class, 'status'])->name('devices.status');
        Route::resource('/devices', \App\Http\Controllers\Admin\DeviceController::class)->except(['show']);
        Route::get('/sms', [AdminController::class, 'sms'])->name('sms');
        Route::get('/apps', [AdminController::class, 'apps'])->name('apps');
        Route::get('/apps/{artifact}/download', [AdminController::class, 'downloadApp'])->name('apps.download');
        Route::get('/texts', [AdminController::class, 'texts'])->name('texts');
        Route::post('/texts', [AdminController::class, 'saveTexts'])->name('texts.save');
        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'saveSettings'])->name('settings.save');
    });
});
