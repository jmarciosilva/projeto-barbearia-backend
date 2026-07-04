<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de "atendimento no estabelecimento" de um cliente sem assinatura,
 * sem profissional nem horario fixos. O staff atribui um horario disponivel
 * (ver WaitlistController::assign) e a entrada vira um Appointment de verdade.
 */
class WaitlistEntry extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'service_id',
        'professional_id',
        'status',
        'notes',
        'appointment_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
