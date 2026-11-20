<?php

namespace App\Services\Billing;

use App\Services\Pixelforge\Data\TokenUsage;
use App\Support\Money\Usd;

/**
 * The price range of a generation before it runs. Text and reasoning token counts
 * are only known after completion, so the actual cost lands somewhere in this range.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenUsage $minTokens,
        public TokenUsage $maxTokens,
        public Usd $minProviderCost,
        public Usd $maxProviderCost,
        public Usd $minCharge,
        public Usd $maxCharge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'min_charge' => $this->minCharge->toDecimal(),
            'max_charge' => $this->maxCharge->toDecimal(),
            'min_tokens' => $this->minTokens->toArray(),
            'max_tokens' => $this->maxTokens->toArray(),
        ];
    }
}
