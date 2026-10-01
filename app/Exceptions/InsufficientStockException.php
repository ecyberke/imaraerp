<?php

namespace App\Exceptions;

use App\Models\Item;
use App\Models\Warehouse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Item $item,
        public readonly Warehouse $warehouse,
        public readonly string $requested,
        public readonly string $available,
    ) {
        parent::__construct(
            "Insufficient stock for item #{$item->id} at warehouse #{$warehouse->id}: "
            ."requested {$requested}, only {$available} available."
        );
    }
}
