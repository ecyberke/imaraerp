<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QualityCheck extends Model
{
    protected $fillable = [
        'tenant_id',
        'checkable_type',
        'checkable_id',
        'result',
        'evaluator_id',
        'disposition',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function checkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
