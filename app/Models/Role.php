<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The fixed RBAC role catalog (architecture doc §11): Sales, Procurement,
 * Warehouse, Finance, Project Manager, Site Supervisor, HR Manager,
 * Asset Manager, Admin. Seeded by the create_roles_table migration.
 * Global, not tenant-scoped (see that migration's docblock).
 */
class Role extends Model
{
    protected $fillable = [
        'name',
        'label',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
