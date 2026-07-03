<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'subscription_plan_id',
        'status',
        'payment_status',
        'starts_on',
        'renews_on',
        'ends_on',
        'last_payment_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'renews_on' => 'date',
            'ends_on' => 'date',
            'last_payment_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
