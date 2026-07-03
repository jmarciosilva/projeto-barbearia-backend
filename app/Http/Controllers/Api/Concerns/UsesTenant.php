<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

trait UsesTenant
{
    /**
     * Recupera o tenant do usuario autenticado para isolar os dados do estabelecimento.
     */
    protected function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;

        abort_if(! $tenantId, 403, 'Usuario sem estabelecimento vinculado.');

        return (int) $tenantId;
    }
}
