<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $event
 */
function deliver(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event);

    return test()->call('POST', '/api/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? pixelforgeSignature($body),
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], content: $body);
}

/**
 * @return array<string, mixed>
 */
function succeededPayload(Generation $generation, array $overrides = []): array
{
    return pixelforgeGeneration(array_replace([
        'id' => $generation->provider_id,
        'status' => 'succeeded',
        'metadata' => ['local_id' => $generation->id],
        // 7*5 + 1100*40 + 1337*12.5 = 60747.5 per 1M tokens = $0.0607475
        'usage' => [
            'input_text_tokens' => 7,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1337,
            'total_tokens' => 2444,
        ],
        'image_url' => 'https://pixelforge.promptedit.dev/images/abc.png?sig=1',
        'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
        'started_at' => now()->subSeconds(20)->toIso8601ZuluString(),
        'completed_at' => now()->toIso8601ZuluString(),
    ], $overrides));
}

describe('signature verification', function () {
    it('rejects requests without a signature', function () {
        deliver(pixelforgeEvent('ping', []), signature: '')->assertStatus(400);
    });

    it('rejects a signature made with the wrong secret', function () {
        $event = pixelforgeEvent('ping', []);

        deliver($event, pixelforgeSignature(json_encode($event), secret: 'whsec_wrong'))->assertStatus(400);
    });

    it('rejects a signature over a different body', function () {
        $signature = pixelforgeSignature(json_encode(pixelforgeEvent('ping', [])));

        deliver(pixelforgeEvent('ping', []), $signature)->assertStatus(400);
    });

    it('rejects stale timestamps to prevent replays', function () {
        $event = pixelforgeEvent('ping', []);

        deliver($event, pixelforgeSignature(json_encode($event), now()->subMinutes(6)->getTimestamp()))->assertStatus(400);
    });

    it('rejects malformed headers', function () {
        deliver(pixelforgeEvent('ping', []), 'v1=abc')->assertStatus(400);
        deliver(pixelforgeEvent('ping', []), 'garbage')->assertStatus(400);
    });

    it('accepts a valid signature among several v1 values', function () {
        $event = pixelforgeEvent('ping', []);
        $body = json_encode($event);
        $timestamp = now()->getTimestamp();
        $valid = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test_secret');

        deliver($event, "t={$timestamp},v1=deadbeef,v1={$valid}")->assertNoContent();
    });
});

it('moves a generation to processing', function () {
    $generation = Generation::factory()->queued()->create();

    deliver(pixelforgeEvent('generation.processing', pixelforgeGeneration([
        'id' => $generation->provider_id,
        'status' => 'processing',
        'started_at' => now()->toIso8601ZuluString(),
    ])))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at)->not->toBeNull();
});

it('charges the exact cost plus markup once a generation succeeds', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->processing()->for($user)->create();

    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation)))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos->toDecimal())->toBe('0.060747500')
        ->and($generation->charged_nanos->toDecimal())->toBe('0.075934375')
        ->and($generation->reasoning_tokens)->toBe(1337)
        ->and($generation->image_url)->toContain('abc.png')
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('0.924065625')
        ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::GenerationCharge);

    Queue::assertPushed(StoreGenerationImage::class);
});

it('never records the same event twice', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->processing()->for($user)->create();
    $event = pixelforgeEvent('generation.succeeded', succeededPayload($generation));

    deliver($event)->assertNoContent();
    deliver($event)->assertNoContent();
    deliver($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($user->creditTransactions()->count())->toBe(1)
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('0.924065625');
});

it('settles once even when different events report the same success', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->processing()->for($user)->create();

    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation)))->assertNoContent();
    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation)))->assertNoContent();

    expect(WebhookEvent::count())->toBe(2)
        ->and($user->creditTransactions()->count())->toBe(1)
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('0.924065625');
});

it('ignores events that arrive out of order', function () {
    $generation = Generation::factory()->queued()->create();

    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation)))->assertNoContent();
    deliver(pixelforgeEvent('generation.processing', pixelforgeGeneration([
        'id' => $generation->provider_id,
        'status' => 'processing',
    ])))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never moves a failed generation to succeeded', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->failed()->for($user)->create();

    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation)))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('1.000000000');
});

it('charges nothing for a failed generation and releases the hold', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->processing()->for($user)->create();

    expect($user->availableBalance()->toDecimal())->toBe('0.913750000');

    deliver(pixelforgeEvent('generation.failed', pixelforgeGeneration([
        'id' => $generation->provider_id,
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => now()->toIso8601ZuluString(),
    ])))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->charged_nanos->isZero())->toBeTrue()
        ->and($user->creditTransactions()->count())->toBe(0)
        ->and($user->availableBalance()->toDecimal())->toBe('1.000000000');
});

it('matches a webhook that arrives before our create request returned', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->for($user)->create(); // still pending, no provider id yet

    $payload = succeededPayload($generation, ['id' => 'gen_early_bird']);
    deliver(pixelforgeEvent('generation.succeeded', $payload))->assertNoContent();

    $generation->refresh();
    expect($generation->provider_id)->toBe('gen_early_bird')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nanos->toDecimal())->toBe('0.075934375');
});

it('acknowledges events for generations it does not know', function () {
    deliver(pixelforgeEvent('generation.succeeded', pixelforgeGeneration(['id' => 'gen_unknown', 'status' => 'succeeded'])))
        ->assertNoContent();

    expect(Generation::count())->toBe(0);
});

it('acknowledges ping events', function () {
    deliver(pixelforgeEvent('ping', []))->assertNoContent();

    expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
});

it('records provider cost but does not charge when we had already told the user it failed', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Failed,
        'error_code' => 'submission_abandoned',
        'charged_nanos' => 0,
        'provider_cost_nanos' => 0,
    ]);

    deliver(pixelforgeEvent('generation.succeeded', succeededPayload($generation, ['id' => 'gen_late'])))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos->toDecimal())->toBe('0.060747500')
        ->and($generation->charged_nanos->isZero())->toBeTrue()
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('1.000000000');
});
