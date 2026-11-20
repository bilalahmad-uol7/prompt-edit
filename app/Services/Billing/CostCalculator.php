<?php

namespace App\Services\Billing;

use App\Services\Pixelforge\Data\TokenRates;
use App\Services\Pixelforge\Data\TokenUsage;
use App\Support\Money\Usd;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns token counts into money. The single place where prices are computed.
 */
class CostCalculator
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private readonly int $markupPercent) {}

    /**
     * What Pixelforge charges for the given usage at the given per-1M-token rates.
     *
     * With Pixelforge's published rates the result is always an exact number of nanodollars.
     * Should a rate ever need more precision, the remainder is rounded up rather than lost.
     */
    public function providerCost(TokenUsage $usage, TokenRates $rates): Usd
    {
        $total = BigDecimal::zero()
            ->plus($rates->inputText->multipliedBy($usage->inputText))
            ->plus($rates->inputImage->multipliedBy($usage->inputImage))
            ->plus($rates->output->multipliedBy($usage->output))
            ->plus($rates->reasoning->multipliedBy($usage->reasoning))
            ->dividedBy(self::TOKENS_PER_RATE_UNIT, Usd::SCALE, RoundingMode::Up);

        return Usd::of($total);
    }

    /**
     * What we charge the user for a given provider cost.
     */
    public function userCharge(Usd $providerCost): Usd
    {
        return $providerCost->withMarkup($this->markupPercent);
    }

    public function markupPercent(): int
    {
        return $this->markupPercent;
    }
}
