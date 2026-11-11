<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepositController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WithdrawalController;
use Illuminate\Support\Facades\Route;

// Landing -> admin panel
Route::get('/', fn () => redirect()->route('admin.login'));

Route::prefix('admin')->name('admin.')->group(function () {

    // ---------------------------------------------------------------- guest/auth
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');

    Route::middleware('admin')->group(function () {
        Route::get('/2fa/challenge', [AdminAuthController::class, 'showChallenge'])->name('2fa.challenge');
        Route::post('/2fa/verify', [AdminAuthController::class, 'verifyChallenge'])
            ->middleware('throttle:login')
            ->name('2fa.verify');

        Route::get('/2fa/setup', [AdminAuthController::class, 'showSetup'])
            ->middleware('admin.2fa')
            ->name('2fa.setup');
        Route::post('/2fa/confirm', [AdminAuthController::class, 'confirmSetup'])
            ->middleware('admin.2fa')
            ->name('2fa.confirm');
    });

    Route::post('/logout', [AdminAuthController::class, 'logout'])
        ->middleware('admin')
        ->name('logout');

    // ------------------------------------------------------------ protected panel
    Route::middleware(['admin', 'admin.2fa'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/pending', [WithdrawalController::class, 'index'])
            ->defaults('status', 'pending_manual')
            ->name('withdrawals.pending');
        Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
        Route::post('/withdrawals/{withdrawal}/approve', [WithdrawalController::class, 'approve'])->name('withdrawals.approve');
        Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject'])->name('withdrawals.reject');

        Route::get('/deposits', [DepositController::class, 'index'])->name('deposits.index');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('/users/{user}/balance', [UserController::class, 'adjustBalance'])->name('users.balance');
        Route::post('/users/{user}/freeze', [UserController::class, 'toggleFreeze'])->name('users.freeze');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    });
});
