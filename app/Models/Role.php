<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The fixed RBAC role catalog (architecture doc §11): Sales, Procurement,
 * Warehouse, Finance, Project Manager, Site Supervisor, HR Manager,
 * Asset Manager, Admin. Tenant-scoped (§11/v20) — every tenant gets its own
 * copy of the same nine rows, seeded on Tenant creation, not a shared
 * global table. See Tenant::seedDefaultRoles() and CATALOG below.
 *
 * Explicitly NOT a permission matrix: Policy classes check role *name* in
 * code, not a stored per-role permission list. A tenant-defined tenth role
 * would be an inert row until a Permission/RolePermission layer exists
 * (deferred to backlog, §14).
 */
class Role extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'label',
    ];

    /** The one fixed role set every tenant is seeded with (§11). */
    public const CATALOG = [
        'sales' => 'Sales',
        'procurement' => 'Procurement',
        'warehouse' => 'Warehouse',
        'finance' => 'Finance',
        'project_manager' => 'Project Manager',
        'site_supervisor' => 'Site Supervisor',
        'hr_manager' => 'HR Manager',
        'asset_manager' => 'Asset Manager',
        'admin' => 'Admin',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
