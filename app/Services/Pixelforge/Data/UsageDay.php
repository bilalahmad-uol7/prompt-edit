<?php

namespace App\Services\Pixelforge\Data;

use App\Support\Money\Usd;

/**
 * One UTC day of GET /v1/usage: what Pixelforge billed for generations completed that day.
 */
final readonly class UsageDay
{
    public function __construct(
        public string $date,
        public int $generations,
        public Usd $cost,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            date: $row['date'],
            generations: (int) $row['generations'],
            cost: Usd::of($row['cost_usd']),
        );
    }
}
