<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'price_cents',
        'billing_period',
        'usage_limit',
        'allowed_weekdays',
        'allowed_start_time',
        'allowed_end_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_weekdays' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'subscription_plan_service')
            ->withPivot(['included_quantity', 'discount_percentage'])
            ->withTimestamps();
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }
}
