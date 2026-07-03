<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsage extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_subscription_id',
        'service_id',
        'appointment_id',
        'used_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClientSubscription::class, 'client_subscription_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
