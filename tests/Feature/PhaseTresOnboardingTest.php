<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTresOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_gets_invite_code_automatically_on_register_owner(): void
    {
        $this->ownerToken('Barbearia Convite', 'dono@example.com');

        $tenant = Tenant::where('name', 'Barbearia Convite')->firstOrFail();

        $this->assertNotEmpty($tenant->invite_code);
        $this->assertSame(6, strlen($tenant->invite_code));
    }

    public function test_client_can_self_register_with_valid_invite_code(): void
    {
        $this->ownerToken('Salao Um', 'dono1@example.com');
        $tenant = Tenant::where('name', 'Salao Um')->firstOrFail();

        $lookup = $this->getJson('/api/tenants/by-invite-code/'.$tenant->invite_code)
            ->assertOk();
        $this->assertSame($tenant->id, $lookup->json('id'));

        $register = $this->postJson('/api/auth/register-client', [
            'invite_code' => strtolower($tenant->invite_code),
            'client' => [
                'name' => 'Maria Cliente',
                'email' => 'maria@example.com',
                'phone' => '11988887777',
                'password' => 'senha12345',
            ],
        ])->assertCreated();

        $this->assertSame($tenant->id, $register->json('tenant.id'));
        $this->assertSame('customer', $register->json('user.role'));

        $token = $register->json('token');
        $this->withToken($token)->getJson('/api/me/client')
            ->assertOk()
            ->assertJsonPath('name', 'Maria Cliente');
    }

    public function test_client_self_register_fails_with_invalid_invite_code(): void
    {
        $this->postJson('/api/auth/register-client', [
            'invite_code' => 'ZZZZZZ',
            'client' => [
                'name' => 'Cliente Invalido',
                'email' => 'invalido@example.com',
                'phone' => '11977776666',
                'password' => 'senha12345',
            ],
        ])->assertUnprocessable();
    }

    public function test_client_self_register_fails_without_invite_code_or_tenant_id(): void
    {
        $this->postJson('/api/auth/register-client', [
            'client' => [
                'name' => 'Sem Vinculo',
                'email' => 'semvinculo@example.com',
                'phone' => '11966665555',
                'password' => 'senha12345',
            ],
        ])->assertUnprocessable();
    }

    public function test_client_can_self_register_via_public_directory(): void
    {
        $this->ownerToken('Salao Dois', 'dono2@example.com');
        $tenant = Tenant::where('name', 'Salao Dois')->firstOrFail();

        $directory = $this->getJson('/api/tenants/directory')->assertOk();
        $this->assertContains($tenant->id, collect($directory->json())->pluck('id')->all());
        $this->assertArrayNotHasKey('document', $directory->json()[0]);

        $this->postJson('/api/auth/register-client', [
            'tenant_id' => $tenant->id,
            'client' => [
                'name' => 'Joao Avulso',
                'email' => 'joao@example.com',
                'phone' => '11955554444',
                'password' => 'senha12345',
            ],
        ])->assertCreated();
    }

    public function test_client_self_register_respects_phone_uniqueness_per_tenant(): void
    {
        $this->ownerToken('Salao Tres', 'dono3@example.com');
        $tenant = Tenant::where('name', 'Salao Tres')->firstOrFail();

        $this->postJson('/api/auth/register-client', [
            'invite_code' => $tenant->invite_code,
            'client' => [
                'name' => 'Primeiro',
                'email' => 'primeiro@example.com',
                'phone' => '11944443333',
                'password' => 'senha12345',
            ],
        ])->assertCreated();

        $this->postJson('/api/auth/register-client', [
            'invite_code' => $tenant->invite_code,
            'client' => [
                'name' => 'Segundo',
                'email' => 'segundo@example.com',
                'phone' => '11944443333',
                'password' => 'senha12345',
            ],
        ])->assertUnprocessable();
    }

    public function test_owner_can_regenerate_invite_code_and_old_one_stops_working(): void
    {
        $token = $this->ownerToken('Salao Quatro', 'dono4@example.com');
        $tenant = Tenant::where('name', 'Salao Quatro')->firstOrFail();
        $oldCode = $tenant->invite_code;

        $response = $this->withToken($token)
            ->postJson('/api/tenant/invite-code/regenerate')
            ->assertOk();

        $newCode = $response->json('invite_code');
        $this->assertNotSame($oldCode, $newCode);
        // O app Flutter (TenantModel.fromJson) exige `saas_subscription` em toda resposta de tenant.
        $this->assertNotNull($response->json('saas_subscription'));

        $this->getJson('/api/tenants/by-invite-code/'.$oldCode)->assertNotFound();
        $this->getJson('/api/tenants/by-invite-code/'.$newCode)->assertOk();
    }

    private function ownerToken(string $tenantName, string $email): string
    {
        return $this->postJson('/api/auth/register-owner', [
            'tenant' => [
                'name' => $tenantName,
                'business_type' => 'barbershop',
            ],
            'owner' => [
                'name' => 'Responsavel',
                'email' => $email,
                'password' => 'password123',
            ],
        ])->assertCreated()->json('token');
    }
}
