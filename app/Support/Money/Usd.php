<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\Castable;
use JsonSerializable;
use Stringable;

/**
 * An exact amount of US dollars, stored as an integer number of nanodollars (1e-9 USD).
 *
 * Pixelforge reports costs as decimal strings with nine decimal places, so nanodollars
 * represent every provider amount exactly. Floats are never used for money.
 */
final readonly class Usd implements Castable, JsonSerializable, Stringable
{
    public const int SCALE = 9;

    private function __construct(public int $nanos) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    /**
     * Parse an exact decimal amount such as "0.046125000" or "5".
     *
     * Throws when the amount has more than nine decimal places, unless a rounding mode is given.
     */
    public static function of(BigNumber|string|int $amount, RoundingMode $roundingMode = RoundingMode::Unnecessary): self
    {
        $nanos = BigDecimal::of($amount)
            ->toScale(self::SCALE, $roundingMode)
            ->getUnscaledValue()
            ->toInt();

        return new self($nanos);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function negate(): self
    {
        return new self(-$this->nanos);
    }

    /**
     * Add a percentage markup, rounding any sub-nanodollar remainder up so we never under-charge.
     */
    public function withMarkup(int $percent): self
    {
        $marked = BigDecimal::of($this->nanos)
            ->multipliedBy(100 + $percent)
            ->dividedBy(100, 0, RoundingMode::Ceiling);

        return new self($marked->toInt());
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function lessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    /**
     * The exact amount as a decimal string with nine places, e.g. "0.373875000".
     */
    public function toDecimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->nanos, self::SCALE);
    }

    /**
     * Human readable amount for flash messages and logs, e.g. "$0.3739".
     *
     * @param  int<0, 9>  $decimals
     */
    public function format(int $decimals = 4): string
    {
        $rounded = BigDecimal::ofUnscaledValue(abs($this->nanos), self::SCALE)
            ->toScale($decimals, RoundingMode::HalfUp);

        return ($this->nanos < 0 ? '-' : '').'$'.$rounded;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    public static function castUsing(array $arguments): UsdCast
    {
        return new UsdCast;
    }
}
