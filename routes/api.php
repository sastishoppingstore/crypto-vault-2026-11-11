<?php

use App\Http\Controllers\Api\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\DepositAddressController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public endpoints
|--------------------------------------------------------------------------
*/

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:login');

// Deposit webhooks (HMAC verified, see config/webhooks.php)
Route::post('webhooks/{provider}', WebhookController::class)->middleware('webhook.signature');

/*
|--------------------------------------------------------------------------
| Authenticated user API (Sanctum token, throttle:api)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // 2FA lifecycle
    Route::get('2fa/status', [TwoFactorController::class, 'status']);
    Route::post('2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('2fa/disable', [TwoFactorController::class, 'disable'])->middleware('2fa:2fa-disable');

    // Core wallet API
    Route::post('deposit/address', [DepositAddressController::class, 'store']);
    Route::get('balance', [BalanceController::class, 'index']);
    Route::get('transactions', [TransactionController::class, 'index']);

    // Withdrawals — balance check + funds lock + 2FA + strict rate limit
    Route::get('withdrawals', [WithdrawalController::class, 'index']);
    Route::post('withdraw/request', [WithdrawalController::class, 'store'])
        ->middleware(['throttle:withdrawals', '2fa:withdrawal']);
});

/*
|--------------------------------------------------------------------------
| Admin API (token with role admin)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'admin', 'throttle:api'])
    ->prefix('admin')
    ->group(function () {
        Route::get('withdrawals/pending', [AdminWithdrawalController::class, 'pending']);
        Route::post('withdrawals/approve/{withdrawal}', [AdminWithdrawalController::class, 'approve']);
        Route::post('withdrawals/reject/{withdrawal}', [AdminWithdrawalController::class, 'reject']);
        Route::get('stats', [AdminWithdrawalController::class, 'stats']);
    });
