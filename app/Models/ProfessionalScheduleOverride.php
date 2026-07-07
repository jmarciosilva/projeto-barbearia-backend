<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste pontual do horario do profissional para uma data especifica (ver
 * migration 2026_07_07_150000_create_professional_schedule_overrides_table).
 */
class ProfessionalScheduleOverride extends Model
{
    protected $fillable = [
        'tenant_id',
        'professional_id',
        'date',
        'is_off',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        // Formato explicito: sem isso, o Eloquent grava com hora (Y-m-d H:i:s)
        // e a comparacao por data pura no calculo de ocupacao falha.
        'date' => 'date:Y-m-d',
        'is_off' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
