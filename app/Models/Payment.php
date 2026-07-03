<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_subscription_id',
        'amount_cents',
        'method',
        'status',
        'due_on',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClientSubscription::class, 'client_subscription_id');
    }
}
