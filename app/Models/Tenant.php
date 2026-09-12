<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'plan_tier',
    ];

    protected static function booted(): void
    {
        // Role is tenant-scoped (§11/v20): every tenant gets its own copy of
        // the fixed 9-role catalog, seeded the moment the Tenant row exists —
        // UserInvitation.role_id needs a real, tenant-owned Role to reference
        // before an admin is ever invited, which happens before onboarding
        // (Chart of Accounts seed, DocumentSequence init) runs.
        static::created(function (Tenant $tenant) {
            $tenant->seedDefaultRoles();
        });
    }

    public function seedDefaultRoles(): void
    {
        $now = now();

        $rows = collect(Role::CATALOG)->map(fn ($label, $name) => [
            'tenant_id' => $this->getKey(),
            'name' => $name,
            'label' => $label,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('roles')->insertOrIgnore($rows);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}
