<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSubscriptionGrant extends Model
{
    protected $fillable = [
        'tenant_id',
        'admin_user_id',
        'saas_plan_id',
        'months_added',
        'previous_current_period_ends_at',
        'new_current_period_ends_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_current_period_ends_at' => 'datetime',
            'new_current_period_ends_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }
}
