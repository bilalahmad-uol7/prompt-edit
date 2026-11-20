<?php

namespace App\Services\Billing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\TokenUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Predicts the cost range of a generation from Pixelforge's published token rules.
 */
class CostEstimator
{
    public function __construct(private readonly CostCalculator $calculator) {}

    public function estimate(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        Pricing $pricing,
    ): CostEstimate {
        [$minText, $maxText] = $this->textTokenRange($prompt, $pricing);
        $reasoning = $pricing->reasoningRangeFor($quality);
        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokensFor($quality, $size);

        $minTokens = new TokenUsage($minText, $imageTokens, $outputTokens, $reasoning['min']);
        $maxTokens = new TokenUsage($maxText, $imageTokens, $outputTokens, $reasoning['max']);

        $minProviderCost = $this->calculator->providerCost($minTokens, $pricing->rates);
        $maxProviderCost = $this->calculator->providerCost($maxTokens, $pricing->rates);

        return new CostEstimate(
            minTokens: $minTokens,
            maxTokens: $maxTokens,
            minProviderCost: $minProviderCost,
            maxProviderCost: $maxProviderCost,
            minCharge: $this->calculator->userCharge($minProviderCost),
            maxCharge: $this->calculator->userCharge($maxProviderCost),
        );
    }

    /**
     * Pixelforge counts about one text token per N Unicode characters (not bytes),
     * and the real count lands within ±variance of that estimate.
     *
     * @return array{0: int, 1: int}
     */
    private function textTokenRange(string $prompt, Pricing $pricing): array
    {
        $characters = mb_strlen($prompt, 'UTF-8');
        $estimate = BigDecimal::of($characters)->dividedBy($pricing->charactersPerTextToken, 0, RoundingMode::Ceiling);

        $min = $estimate->multipliedBy(BigDecimal::one()->minus($pricing->textTokenVariance))->toScale(0, RoundingMode::Floor);
        $max = $estimate->multipliedBy(BigDecimal::one()->plus($pricing->textTokenVariance))->toScale(0, RoundingMode::Ceiling);

        return [$min->toInt(), $max->toInt()];
    }
}
