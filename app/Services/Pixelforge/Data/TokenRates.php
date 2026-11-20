<?php

namespace App\Services\Pixelforge\Data;

use Brick\Math\BigDecimal;

/**
 * USD price per one million tokens, per token type. Kept as exact decimals.
 */
final readonly class TokenRates
{
    public function __construct(
        public BigDecimal $inputText,
        public BigDecimal $inputImage,
        public BigDecimal $output,
        public BigDecimal $reasoning,
    ) {}

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: BigDecimal::of($rates['input_text_tokens']),
            inputImage: BigDecimal::of($rates['input_image_tokens']),
            output: BigDecimal::of($rates['output_tokens']),
            reasoning: BigDecimal::of($rates['reasoning_tokens']),
        );
    }

    /**
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => (string) $this->inputText,
            'input_image_tokens' => (string) $this->inputImage,
            'output_tokens' => (string) $this->output,
            'reasoning_tokens' => (string) $this->reasoning,
        ];
    }
}
