<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Markup extends Model
{
    protected $fillable = [
        'tenant_id',
        'boq_id',
        'section_id',
        'name',
        'percentage',
    ];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boq()
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function section()
    {
        return $this->belongsTo(BoqSection::class, 'section_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
