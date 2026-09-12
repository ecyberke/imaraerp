<?php

namespace App\Exceptions;

use App\Models\Tenant;
use RuntimeException;

class NoOpenAccountingPeriodException extends RuntimeException
{
    public function __construct(Tenant $tenant)
    {
        parent::__construct("Tenant #{$tenant->id} has no open AccountingPeriod to post into.");
    }
}
