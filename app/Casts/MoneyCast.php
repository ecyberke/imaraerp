<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Backs a `_cents` bigInteger column with a Money value object -
 * JournalLine's debit_cents/credit_cents never surface as a raw int or
 * float anywhere in application code.
 *
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return Money::fromCents((int) ($value ?? 0));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if ($value instanceof Money) {
            return $value->cents();
        }

        if (is_int($value)) {
            return $value;
        }

        return Money::fromMajor($value)->cents();
    }
}
