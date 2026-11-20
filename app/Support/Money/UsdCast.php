<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a Usd value in a BIGINT column as nanodollars.
 *
 * @implements CastsAttributes<Usd|null, Usd|int|null>
 */
class UsdCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Usd
    {
        return $value === null ? null : Usd::fromNanos((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value instanceof Usd ? $value->nanos : $value;
    }
}
