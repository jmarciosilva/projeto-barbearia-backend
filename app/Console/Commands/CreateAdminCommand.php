<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Unica forma de criar um administrador da plataforma (roadmap Fase 5): sem
 * endpoint publico nem autenticado nesta fase, ja que sao so 2 pessoas com
 * acesso ao servidor. Nao roda no DatabaseSeeder de proposito — esse seeder
 * ja usa senha previsivel (demo12345) e roda contra ambiente com saloes
 * reais, entao um admin ali seria a conta de maior risco do sistema.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {name} {email} {password}';

    protected $description = 'Cria uma conta de administrador da plataforma (sem tenant proprio)';

    public function handle(): int
    {
        $data = [
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $this->argument('password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'admin',
            'tenant_id' => null,
            'password' => $data['password'],
        ]);

        $this->info("Administrador \"{$data['name']}\" ({$data['email']}) criado com sucesso.");

        return self::SUCCESS;
    }
}
