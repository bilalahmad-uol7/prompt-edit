<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Billing\CostCalculator;
use App\Services\Billing\CostEstimator;
use App\Services\Pixelforge\Data\TokenRates;
use App\Services\Pixelforge\Data\TokenUsage;

beforeEach(function () {
    $this->calculator = new CostCalculator(markupPercent: 25);
    $this->estimator = new CostEstimator($this->calculator);
});

it('reproduces the maximum cost worked example from the Pixelforge docs', function () {
    // high quality, 1536x1024, 400 character prompt, 2 reference images.
    $estimate = $this->estimator->estimate(
        prompt: str_repeat('a', 400),
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImageCount: 2,
        pricing: pricingFixture(),
    );

    expect($estimate->maxTokens->toArray())->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ])
        ->and($estimate->maxProviderCost->toDecimal())->toBe('0.373875000')
        ->and($estimate->maxCharge->toDecimal())->toBe('0.467343750');
});

it('computes the minimum of the range from the lower token bounds', function () {
    $estimate = $this->estimator->estimate(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2, pricingFixture());

    // 85 text (100 - 15%), 1530 image, 6450 output, 1000 reasoning
    expect($estimate->minTokens->toArray())->toBe([
        'input_text_tokens' => 85,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 1000,
    ])
        ->and($estimate->minProviderCost->toDecimal())->toBe('0.286225000');
});

it('counts unicode characters, not bytes', function () {
    // 8 emoji = 8 characters (32 bytes) -> 2 estimated tokens -> max ceil(2 * 1.15) = 3
    $estimate = $this->estimator->estimate(str_repeat('🎨', 8), ImageSize::Square, ImageQuality::Low, 0, pricingFixture());

    expect($estimate->maxTokens->inputText)->toBe(3)
        ->and($estimate->minTokens->inputText)->toBe(1);
});

it('rounds partial text tokens up', function () {
    // 5 characters -> ceil(5 / 4) = 2 tokens
    $estimate = $this->estimator->estimate('abcde', ImageSize::Square, ImageQuality::Low, 0, pricingFixture());

    expect($estimate->maxTokens->inputText)->toBe(3)
        ->and($estimate->minTokens->inputText)->toBe(1)
        ->and($estimate->minTokens->reasoning)->toBe(0)
        ->and($estimate->maxTokens->reasoning)->toBe(500);
});

it('calculates the exact provider cost of actual usage at the generation rates', function () {
    $usage = new TokenUsage(inputText: 7, inputImage: 0, output: 1100, reasoning: 1337);
    $rates = TokenRates::fromArray(pricingFixture()->rates->toArray());

    // 7*5 + 1100*40 + 1337*12.5 = 35 + 44000 + 16712.5 = 60747.5 per 1M -> 0.0607475
    $cost = $this->calculator->providerCost($usage, $rates);

    expect($cost->toDecimal())->toBe('0.060747500')
        ->and($this->calculator->userCharge($cost)->toDecimal())->toBe('0.075934375');
});

it('uses the rates fixed on the generation, not current pricing', function () {
    $usage = new TokenUsage(inputText: 10, inputImage: 0, output: 280, reasoning: 0);
    $oldRates = TokenRates::fromArray([
        'input_text_tokens' => '2.50',
        'input_image_tokens' => '10.00',
        'output_tokens' => '20.00',
        'reasoning_tokens' => '12.50',
    ]);

    expect($this->calculator->providerCost($usage, $oldRates)->toDecimal())->toBe('0.005625000');
});
