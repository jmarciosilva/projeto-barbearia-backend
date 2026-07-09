<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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
        'is_founder',
        'invite_code',
        'units_count',
        'professional_payment_day',
        'opening_time',
        'closing_time',
        'break_start_time',
        'break_end_time',
    ];

    protected function casts(): array
    {
        return [
            'is_founder' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->invite_code)) {
                $tenant->invite_code = static::generateInviteCode();
            }
        });
    }

    /**
     * Codigo curto (sem 0/O/1/I/L, que se confundem entre si em telas
     * pequenas ou impressos) que o dono compartilha com o cliente por
     * link/QR para o cliente se autocadastrar ja vinculado a este tenant.
     */
    public static function generateInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
            $code = str_replace(['0', 'O', '1', 'I', 'L'], ['2', 'P', '3', 'J', 'K'], $code);
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

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

    public function professionalAdvances(): HasMany
    {
        return $this->hasMany(ProfessionalAdvance::class);
    }

    public function adminSubscriptionGrants(): HasMany
    {
        return $this->hasMany(AdminSubscriptionGrant::class);
    }

    public function saasSubscription(): HasOne
    {
        return $this->hasOne(SaasSubscription::class);
    }

    public function scheduleOverrides(): HasMany
    {
        return $this->hasMany(TenantScheduleOverride::class);
    }
}
