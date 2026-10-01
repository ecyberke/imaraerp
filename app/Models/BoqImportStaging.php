<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class BoqImportStaging extends Model
{
    public const STATUSES = ['pending_review', 'confirmed', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'boq_id',
        'source_file_path',
        'raw_row_data',
        'mapped_item_id',
        'mapped_section',
        'mapped_quantity',
        'mapped_rate_cents',
        'status',
        'reviewed_by',
        'boq_line_id',
    ];

    protected $hidden = ['mapped_rate_cents'];

    protected $appends = ['mapped_rate'];

    protected function casts(): array
    {
        return [
            'raw_row_data' => 'array',
            'mapped_quantity' => 'decimal:4',
            'mapped_rate_cents' => MoneyCast::class,
        ];
    }

    protected function mappedRate(): Attribute
    {
        return Attribute::make(get: fn () => $this->mapped_rate_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boq()
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function mappedItem()
    {
        return $this->belongsTo(Item::class, 'mapped_item_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function boqLine()
    {
        return $this->belongsTo(BoqLine::class, 'boq_line_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
