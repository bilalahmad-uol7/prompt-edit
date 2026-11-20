<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('polls quiet in-flight generations so a lost webhook is not fatal', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $stale = Generation::factory()->processing()->for($user)->create(['last_synced_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->processing()->for($user)->create(['last_synced_at' => now()]);

    Http::fake(["*/generations/{$stale->provider_id}" => Http::response(pixelforgeGeneration([
        'id' => $stale->provider_id,
        'status' => 'succeeded',
        'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2110],
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
        'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
        'completed_at' => now()->toIso8601ZuluString(),
    ]))]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($stale->charged_nanos->toDecimal())->toBe('0.070687500')
        ->and($fresh->refresh()->status)->toBe(GenerationStatus::Processing);

    Http::assertSentCount(1);
});

it('re-queues pending generations whose submission job went missing', function () {
    $stuck = Generation::factory()->create(['updated_at' => now()->subMinutes(10)]);
    $waiting = Generation::factory()->create(['updated_at' => now()->subMinutes(10), 'retry_at' => now()->addSeconds(30)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generation->is($stuck));
});

it('stops polling when Pixelforge rate limits us', function () {
    Generation::factory()->count(3)->processing()->create(['last_synced_at' => now()->subMinutes(5)]);

    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '30'])]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
});

it('refuses a second charge for the same generation at the database level', function () {
    $generation = Generation::factory()->succeeded()->create();
    $attributes = [
        'user_id' => $generation->user_id,
        'generation_id' => $generation->id,
        'type' => CreditTransactionType::GenerationCharge,
        'amount_nanos' => -1,
        'balance_after_nanos' => 0,
    ];

    CreditTransaction::create($attributes);
    CreditTransaction::create($attributes);
})->throws(UniqueConstraintViolationException::class);
