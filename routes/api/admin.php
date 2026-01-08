<?php

declare(strict_types=1);

/**
 * Admin API Routes.
 *
 * All routes in this file are prefixed with 'admin' and require:
 * - auth:sanctum middleware (authentication)
 * - admin middleware (admin role check)
 *
 * This file is loaded from routes/api.php
 */

use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminGuideController;
use App\Http\Controllers\Admin\AdminPayoutController;
use App\Http\Controllers\Admin\AdminStudentController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BrandRankingController;
use App\Http\Controllers\Admin\WithdrawalMethodController;
use App\Http\Controllers\Common\GuideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard & Metrics
|--------------------------------------------------------------------------
*/
Route::get('/dashboard-metrics', [AdminDashboardController::class, 'getMetrics']);
Route::get('/pending-campaigns', [AdminDashboardController::class, 'getPendingCampaigns']);
Route::get('/recent-users', [AdminDashboardController::class, 'getRecentUsers']);

/*
|--------------------------------------------------------------------------
| Campaign Management
|--------------------------------------------------------------------------
*/
Route::prefix('campaigns')->group(function (): void {
    Route::get('/', [AdminCampaignController::class, 'index']);
    Route::get('/{id}', [AdminCampaignController::class, 'show'])->where('id', '[0-9]+');
    Route::patch('/{id}', [AdminCampaignController::class, 'update'])->where('id', '[0-9]+');
    Route::patch('/{id}/approve', [AdminCampaignController::class, 'approve'])->where('id', '[0-9]+');
    Route::patch('/{id}/reject', [AdminCampaignController::class, 'reject'])->where('id', '[0-9]+');
    Route::delete('/{id}', [AdminCampaignController::class, 'destroy'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| User Management
|--------------------------------------------------------------------------
*/
Route::prefix('users')->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::get('/creators', [AdminUserController::class, 'getCreators']);
    Route::get('/brands', [AdminUserController::class, 'getBrands']);
    Route::get('/statistics', [AdminUserController::class, 'getStatistics']);
    Route::patch('/{user}/status', [AdminUserController::class, 'updateStatus'])->where('user', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| Student Verification Management
|--------------------------------------------------------------------------
*/
Route::prefix('students')->group(function (): void {
    Route::get('/', [AdminStudentController::class, 'index']);
    Route::patch('/{student}/trial', [AdminStudentController::class, 'updateTrial'])->where('student', '[0-9]+');
    Route::patch('/{student}/status', [AdminStudentController::class, 'updateStatus'])->where('student', '[0-9]+');
});

Route::prefix('student-requests')->group(function (): void {
    Route::get('/', [AdminStudentController::class, 'getVerificationRequests']);
    Route::patch('/{id}/approve', [AdminStudentController::class, 'approveVerification'])->where('id', '[0-9]+');
    Route::patch('/{id}/reject', [AdminStudentController::class, 'rejectVerification'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| Payout Management
|--------------------------------------------------------------------------
*/
Route::prefix('payouts')->group(function (): void {
    Route::get('/metrics', [AdminPayoutController::class, 'getMetrics']);
    Route::get('/pending', [AdminPayoutController::class, 'getPendingWithdrawals']);
    Route::get('/history', [AdminPayoutController::class, 'getPayoutHistory']);
    Route::get('/disputes', [AdminPayoutController::class, 'getDisputedContracts']);
    Route::get('/verification-report', [AdminPayoutController::class, 'getVerificationReport']);
    Route::get('/{id}/verify', [AdminPayoutController::class, 'verifyWithdrawal'])->where('id', '[0-9]+');
    Route::post('/{id}/process', [AdminPayoutController::class, 'processWithdrawal'])->where('id', '[0-9]+');
    Route::post('/{id}/resolve-dispute', [AdminPayoutController::class, 'resolveDispute'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| Withdrawal Methods Management
|--------------------------------------------------------------------------
*/
Route::apiResource('withdrawal-methods', WithdrawalMethodController::class);
Route::put('/withdrawal-methods/{id}/toggle-active', [WithdrawalMethodController::class, 'toggleActive'])
    ->where('id', '[0-9]+')
;

/*
|--------------------------------------------------------------------------
| Guide Management
|--------------------------------------------------------------------------
*/
Route::prefix('guides')->group(function (): void {
    Route::get('/', [AdminGuideController::class, 'index']);
    Route::get('/{id}', [AdminGuideController::class, 'show'])->where('id', '[0-9]+');
    Route::post('/', [GuideController::class, 'store']);
    Route::put('/{id}', [AdminGuideController::class, 'update'])->where('id', '[0-9]+');
    Route::delete('/{id}', [AdminGuideController::class, 'destroy'])->where('id', '[0-9]+');
});

/*
|--------------------------------------------------------------------------
| Brand Rankings
|--------------------------------------------------------------------------
*/
Route::prefix('brand-rankings')->group(function (): void {
    Route::get('/', [BrandRankingController::class, 'getBrandRankings']);
    Route::get('/comprehensive', [BrandRankingController::class, 'getComprehensiveRankings']);
});
