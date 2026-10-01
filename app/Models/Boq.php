<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Boq extends Model
{
    protected $table = 'boqs';

    protected $fillable = [
        'tenant_id',
        'boqable_type',
        'boqable_id',
        'revised_contract_value_cents',
        'uses_sections',
        'source',
        'source_file_path',
        'status',
    ];

    protected $hidden = ['revised_contract_value_cents'];

    protected $appends = ['revised_contract_value'];

    protected function casts(): array
    {
        return [
            'uses_sections' => 'boolean',
            'revised_contract_value_cents' => MoneyCast::class,
        ];
    }

    protected function revisedContractValue(): Attribute
    {
        return Attribute::make(get: fn () => $this->revised_contract_value_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boqable()
    {
        return $this->morphTo();
    }

    public function sections()
    {
        return $this->hasMany(BoqSection::class, 'boq_id');
    }

    public function lines()
    {
        return $this->hasMany(BoqLine::class, 'boq_id');
    }

    public function markups()
    {
        return $this->hasMany(Markup::class, 'boq_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
