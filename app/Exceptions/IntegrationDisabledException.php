<?php

namespace App\Exceptions;

use App\Models\Tenant;
use RuntimeException;

class IntegrationDisabledException extends RuntimeException
{
    public function __construct(Tenant $tenant, public readonly string $flag)
    {
        parent::__construct("This integration is not enabled for tenant #{$tenant->id} ({$flag}). Contact support to have it switched on.");
    }
}
