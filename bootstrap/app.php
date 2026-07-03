<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Restringe rotas por papel do usuario autenticado, ex: `role:owner`.
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Todas as excecoes da API sao normalizadas em JSON.
         * Isso evita respostas HTML do Laravel no aplicativo mobile.
         */
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'message' => 'Dados invalidos.',
                    'error' => 'validation_error',
                    'errors' => $exception->errors(),
                ], 422);
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Autenticacao obrigatoria.',
                    'error' => 'unauthenticated',
                ], 401);
            }

            if ($exception instanceof AuthorizationException) {
                return response()->json([
                    'message' => 'Acesso nao autorizado.',
                    'error' => 'forbidden',
                ], 403);
            }

            if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'Registro nao encontrado.',
                    'error' => 'not_found',
                ], 404);
            }

            if ($exception instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Requisicao nao pode ser processada.',
                    'error' => 'http_error',
                ], $exception->getStatusCode());
            }

            if ($exception instanceof QueryException) {
                report($exception);

                return response()->json([
                    'message' => 'Erro ao acessar dados.',
                    'error' => 'database_error',
                ], 500);
            }

            report($exception);

            return response()->json([
                'message' => 'Erro interno inesperado.',
                'error' => 'internal_server_error',
            ], 500);
        });
    })->create();
