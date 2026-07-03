<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe uma rota aos papeis informados (ex: `role:owner` ou `role:owner,professional`).
 *
 * Aplica-se depois do `auth:sanctum`, entao sempre existe um usuario autenticado aqui.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()->role, $roles, true), 403, 'Acesso nao autorizado para este papel.');

        return $next($request);
    }
}
