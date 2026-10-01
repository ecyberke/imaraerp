<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    public const TYPES = ['internal', 'contractor'];

    protected $fillable = [
        'tenant_id',
        'type',
        'party_id',
        'employee_id',
        'skill_category',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignments()
    {
        return $this->hasMany(ResourceAssignment::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
