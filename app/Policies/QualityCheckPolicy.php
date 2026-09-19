<?php

namespace App\Policies;

use App\Models\QualityCheck;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryOperationsRoles;

class QualityCheckPolicy
{
    use ChecksInventoryOperationsRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, QualityCheck $qualityCheck): bool
    {
        return $user->tenant_id === $qualityCheck->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canDisposition($user);
    }
}
