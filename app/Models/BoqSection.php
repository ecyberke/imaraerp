<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class BoqSection extends Model
{
    protected $table = 'boq_sections';

    protected $fillable = [
        'tenant_id',
        'boq_id',
        'parent_section_id',
        'name',
        'sequence',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boq()
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function parentSection()
    {
        return $this->belongsTo(BoqSection::class, 'parent_section_id');
    }

    public function childSections()
    {
        return $this->hasMany(BoqSection::class, 'parent_section_id');
    }

    public function lines()
    {
        return $this->hasMany(BoqLine::class, 'section_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
