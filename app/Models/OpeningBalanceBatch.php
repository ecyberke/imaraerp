<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceBatch extends Model
{
    public const STATUSES = ['draft', 'posted'];

    protected $fillable = [
        'tenant_id',
        'as_of_date',
        'status',
        'posted_by',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['as_of_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
