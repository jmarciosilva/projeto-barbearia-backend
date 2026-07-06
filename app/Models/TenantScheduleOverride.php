<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excecao pontual ao horario padrao do tenant para uma data especifica (ver
 * migration 2026_07_06_180001_create_tenant_schedule_overrides_table).
 */
class TenantScheduleOverride extends Model
{
    protected $fillable = [
        'tenant_id',
        'date',
        'is_closed',
        'opens_at',
        'closes_at',
    ];

    protected $casts = [
        // Formato explicito: sem isso, o Eloquent grava com hora (Y-m-d H:i:s)
        // e a comparacao por data pura em `assertWithinBusinessHours` falha.
        'date' => 'date:Y-m-d',
        'is_closed' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
