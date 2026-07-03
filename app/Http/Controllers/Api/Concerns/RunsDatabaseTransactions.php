<?php

namespace App\Http\Controllers\Api\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

trait RunsDatabaseTransactions
{
    /**
     * Executa uma operacao de escrita dentro de transacao explicita.
     *
     * Se qualquer excecao for lancada, o rollback desfaz todas as alteracoes
     * feitas dentro do bloco e a excecao continua subindo para o handler global.
     */
    protected function transaction(Closure $operation): mixed
    {
        DB::beginTransaction();

        try {
            $result = $operation();

            DB::commit();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
