<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Professional extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'email',
        'phone',
        'specialty',
        'commission_percentage',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(ProfessionalAdvance::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'professional_service')->withTimestamps();
    }

    public function subscriptionPlans(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'professional_subscription_plan')->withTimestamps();
    }
}
