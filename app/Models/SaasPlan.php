<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'max_professionals',
        'max_client_subscriptions',
        'max_units',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SaasSubscription::class);
    }
}
