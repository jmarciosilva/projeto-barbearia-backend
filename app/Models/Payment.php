<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_subscription_id',
        'appointment_id',
        'amount_cents',
        'method',
        'status',
        'due_on',
        'paid_at',
        'notes',
    ];

    protected $appends = [
        'received_cents',
        'remaining_cents',
        'is_fully_paid',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClientSubscription::class, 'client_subscription_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function getReceivedCentsAttribute(): int
    {
        if ($this->status === 'paid' && ! $this->relationLoaded('receipts')) {
            return $this->amount_cents;
        }

        if ($this->relationLoaded('receipts')) {
            $sum = (int) $this->receipts->sum('amount_cents');

            return $this->status === 'paid' && $sum === 0 ? $this->amount_cents : $sum;
        }

        $sum = (int) $this->receipts()->sum('amount_cents');

        return $this->status === 'paid' && $sum === 0 ? $this->amount_cents : $sum;
    }

    public function getRemainingCentsAttribute(): int
    {
        if ($this->status === 'paid') {
            return 0;
        }

        return max(0, $this->amount_cents - $this->received_cents);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->remaining_cents <= 0 || $this->status === 'paid';
    }
}
