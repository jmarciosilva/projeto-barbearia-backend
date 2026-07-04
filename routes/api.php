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

    // Dados basicos do estabelecimento sao visiveis para qualquer papel autenticado.
    Route::get('/tenant', [TenantController::class, 'show']);

    // Agendar e uma acao de qualquer papel; cliente so consegue agendar para si mesmo
    // (regra aplicada dentro do AppointmentController, nao aqui).
    Route::post('/appointments', [AppointmentController::class, 'store']);

    // Auto-perfil: cada metodo confere o proprio papel internamente e nunca
    // deixa um usuario ler/editar o registro de outra pessoa (mesmo padrao
    // ja usado em AppointmentController::complete).
    Route::get('/me/client', [ClientController::class, 'me']);
    Route::get('/me/professional', [ProfessionalController::class, 'me']);
    Route::patch('/me/professional', [ProfessionalController::class, 'updateSelf']);
    Route::post('/me/client-subscriptions', [ClientSubscriptionController::class, 'subscribeSelf']);
    Route::post('/me/client-subscriptions/cancel', [ClientSubscriptionController::class, 'cancelSelf']);

    // Catalogo de leitura para montar agendamento: tambem liberado ao cliente
    // (filtrado a itens ativos/proprios dentro de cada controller).
    Route::middleware('role:owner,professional,customer')->group(function () {
        Route::apiResource('professionals', ProfessionalController::class)->only(['index']);
        Route::apiResource('services', ServiceController::class)->only(['index']);
        Route::apiResource('subscription-plans', SubscriptionPlanController::class)->only(['index']);
        Route::apiResource('appointments', AppointmentController::class)->only(['index']);
    });

    // Remarcar/cancelar tambem e permitido ao cliente, mas so no proprio agendamento
    // e com campos restritos (checagem dentro de AppointmentController::update).
    Route::middleware('role:owner,professional,customer')->group(function () {
        Route::match(['put', 'patch'], '/appointments/{appointment}', [AppointmentController::class, 'update']);
    });

    // Operacao do dia a dia do salao: proprietario e profissional compartilham leitura
    // e as acoes de atendimento, mas nao a gestao de catalogo/financeiro.
    Route::middleware('role:owner,professional')->group(function () {
        Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'update']);
        Route::apiResource('client-subscriptions', ClientSubscriptionController::class)->only(['index', 'store']);
        Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    });

    // Gestao de catalogo, financeiro e estabelecimento e exclusiva do proprietario.
    Route::middleware('role:owner')->group(function () {
        Route::patch('/tenant', [TenantController::class, 'update']);
        Route::apiResource('professionals', ProfessionalController::class)->only(['store', 'update']);
        Route::apiResource('services', ServiceController::class)->only(['store', 'update']);
        Route::apiResource('subscription-plans', SubscriptionPlanController::class)->only(['store', 'update']);
        Route::apiResource('client-subscriptions', ClientSubscriptionController::class)->only(['update']);
        Route::apiResource('payments', PaymentController::class)->only(['index', 'store']);
        Route::post('/payments/{payment}/mark-paid', [PaymentController::class, 'markPaid']);
    });
});
