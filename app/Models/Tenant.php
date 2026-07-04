<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'business_type',
        'document',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'timezone',
        'status',
        'units_count',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function professionals(): HasMany
    {
        return $this->hasMany(Professional::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class);
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function saasSubscription(): HasOne
    {
        return $this->hasOne(SaasSubscription::class);
    }
}
