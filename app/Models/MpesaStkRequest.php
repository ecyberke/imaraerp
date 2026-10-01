<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class MpesaStkRequest extends Model
{
    public const STATUSES = ['pending', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'party_id',
        'invoice_id',
        'initiated_by',
        'amount_cents',
        'phone_number',
        'merchant_request_id',
        'checkout_request_id',
        'status',
        'result_code',
        'result_desc',
        'mpesa_receipt_number',
        'payment_id',
        'raw_callback',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'raw_callback' => 'array',
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => Money::fromCents($this->amount_cents));
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
