<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Subcontract extends Model
{
    protected $fillable = [
        'tenant_id',
        'party_id',
        'project_id',
        'boq_id',
        'required_document_types',
        'status',
    ];

    protected function casts(): array
    {
        return ['required_document_types' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function progressClaims()
    {
        return $this->hasMany(ProgressClaim::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
