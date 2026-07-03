<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientSubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'product' => 'Clube do Salao API',
]);

Route::post('/auth/register-owner', [AuthController::class, 'registerOwner']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/tenant', [TenantController::class, 'show']);
    Route::patch('/tenant', [TenantController::class, 'update']);

    Route::apiResource('professionals', ProfessionalController::class)->only(['index', 'store', 'update']);
    Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'update']);
    Route::apiResource('services', ServiceController::class)->only(['index', 'store', 'update']);
    Route::apiResource('subscription-plans', SubscriptionPlanController::class)->only(['index', 'store', 'update']);
    Route::apiResource('client-subscriptions', ClientSubscriptionController::class)->only(['index', 'store', 'update']);
    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'update']);
    Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store']);
    Route::post('/payments/{payment}/mark-paid', [PaymentController::class, 'markPaid']);
});
