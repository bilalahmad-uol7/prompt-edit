<?php

namespace App\Services\Pixelforge\Data;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Brick\Math\BigDecimal;

/**
 * Snapshot of GET /v1/pricing: token rates plus the rules for predicting token counts.
 */
final readonly class Pricing
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public BigDecimal $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $tokens = $payload['tokens'];

        return new self(
            rates: TokenRates::fromArray($payload['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // Cast through string so a JSON float such as 0.15 becomes the exact decimal "0.15".
            textTokenVariance: BigDecimal::of((string) $tokens['text_token_variance']),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    public function outputTokensFor(ImageQuality $quality, ImageSize $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(ImageQuality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value];

        return ['min' => (int) $range['min'], 'max' => (int) $range['max']];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => (string) $this->textTokenVariance,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }
}
