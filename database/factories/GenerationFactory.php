<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money\Usd;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults model a medium 1024x1024 generation with a short prompt and no reference images.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_min_charge_nanos' => Usd::of('0.058125000'),
            'estimated_max_charge_nanos' => Usd::of('0.086250000'),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.Str::random(20),
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        // 10 text, 0 image, 1100 output, 1000 reasoning = 0.056550000 provider cost.
        return $this->processing()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1000,
            'provider_cost_nanos' => Usd::of('0.056550000'),
            'charged_nanos' => Usd::of('0.070687500'),
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => 'The model could not produce an image for this prompt.',
            'provider_cost_nanos' => Usd::zero(),
            'charged_nanos' => Usd::zero(),
            'completed_at' => now(),
        ]);
    }
}
