<?php

use App\Services\Pixelforge\Data\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * @return array<string, mixed>
 */
function pixelforgeFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/pixelforge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

function pricingFixture(): Pricing
{
    return Pricing::fromArray(pixelforgeFixture('pricing'));
}

/**
 * A Pixelforge generation object, shaped like the API docs.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_'.Str::random(20),
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pixelforgeFixture('pricing')['rates'],
        'error' => null,
        'created_at' => now()->toIso8601ZuluString(),
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * Build a Pixelforge webhook event around a generation object.
 *
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $type, array $generation, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601ZuluString(),
        'data' => ['object' => $generation],
    ];
}

/**
 * The Pixelforge-Signature header for a raw body, as Pixelforge computes it.
 */
function pixelforgeSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test_secret'): string
{
    $timestamp ??= now()->getTimestamp();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}
