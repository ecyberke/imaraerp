<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Backs a `_cents` bigInteger column with a Money value object -
 * JournalLine's debit_cents/credit_cents (never null - default 0) and
 * Item.standard_cost_cents/Party.credit_limit_cents (genuinely nullable
 * on some columns - null means "not set", a meaningfully different
 * state from Money::zero()) never surface as a raw int or float
 * anywhere in application code.
 *
 * @implements CastsAttributes<?Money, ?Money>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromCents((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->cents();
        }

        if (is_int($value)) {
            return $value;
        }

        return Money::fromMajor($value)->cents();
    }
}
