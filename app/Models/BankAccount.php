<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    public const STATUSES = ['active', 'closed'];

    protected $fillable = [
        'tenant_id',
        'bank_name',
        'account_number',
        'currency_id',
        'gl_account_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function glAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
