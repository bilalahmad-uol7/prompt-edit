<?php

use App\Actions\Generations\SubmitGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

function pixelforgeError(int $status, string $code, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'error', 'code' => $code, 'message' => "Simulated {$code}"]], $status, $headers);
}

it('submits with the generation id as idempotency key and local id metadata', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);

    Http::fake([
        '*/generations' => Http::response(pixelforgeGeneration([
            'id' => 'gen_accepted',
            'status' => 'queued',
            'metadata' => ['local_id' => $generation->id],
        ]), 202),
    ]);

    expect(app(SubmitGeneration::class)->handle($generation))->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->header('Idempotency-Key')[0] === $generation->id
        && $request->hasHeader('Authorization', 'Bearer pk_test_key')
        && $request['metadata'] === ['local_id' => $generation->id]
        && $request['reference_images'] === ['https://example.com/a.png']
        && $request['size'] === '1024x1024'
        && $request['quality'] === 'medium');

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_accepted')
        ->and($generation->submit_attempts)->toBe(1)
        ->and($generation->submitted_at)->not->toBeNull();
});

it('waits for capacity when the concurrency limit is reached, then retries with the same key', function () {
    $generation = Generation::factory()->create();

    Http::fakeSequence('*/generations')
        ->pushResponse(pixelforgeError(429, 'concurrency_limit_exceeded', ['Retry-After' => '5']))
        ->push(pixelforgeGeneration(['id' => 'gen_ok', 'status' => 'queued']), 202);

    $retryIn = app(SubmitGeneration::class)->handle($generation);

    expect($retryIn)->toBeGreaterThanOrEqual(5)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->error_code)->toBe('concurrency_limit_exceeded')
        ->and($generation->retry_at)->not->toBeNull();

    expect(app(SubmitGeneration::class)->handle($generation))->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->error_code)->toBeNull();

    $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
    expect($keys->all())->toBe([$generation->id]);
});

it('retries when Pixelforge is overloaded or rate limited', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => pixelforgeError($status, $code)]);

    expect(app(SubmitGeneration::class)->handle($generation))->toBeGreaterThan(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded' => [503, 'overloaded'],
    'rate limited' => [429, 'rate_limit_exceeded'],
    'unknown 502' => [502, 'bad_gateway'],
]);

it('retries when the request times out, because the outcome is unknown', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    expect(app(SubmitGeneration::class)->handle($generation))->toBeGreaterThan(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->error_code)->toBe('provider_unreachable');
});

it('fails without charging when Pixelforge rejects the request', function (int $status, string $code, string $message) {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->for($user)->create();
    Http::fake(['*/generations' => pixelforgeError($status, $code)]);

    expect(app(SubmitGeneration::class)->handle($generation))->toBeNull();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toContain($message)
        ->and($generation->charged_nanos->isZero())->toBeTrue()
        ->and($user->availableBalance()->toDecimal())->toBe('1.000000000');
})->with([
    'content policy' => [400, 'content_policy_violation', 'content policy'],
    'daily quota' => [429, 'daily_limit_exceeded', 'daily generation quota'],
    'validation' => [422, 'validation_failed', 'rejected the request parameters'],
    'bad api key' => [401, 'invalid_api_key', 'on our side'],
]);

it('does not call Pixelforge for a generation that is no longer pending', function () {
    $generation = Generation::factory()->queued()->create();

    expect(app(SubmitGeneration::class)->handle($generation))->toBeNull();

    Http::assertNothingSent();
});

it('settles immediately when an idempotent replay returns a finished generation', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $generation = Generation::factory()->for($user)->create();

    Http::fake(['*/generations' => Http::response(pixelforgeGeneration([
        'id' => 'gen_replayed',
        'status' => 'succeeded',
        'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2110],
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
        'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
        'completed_at' => now()->toIso8601ZuluString(),
    ]), 202, ['Idempotent-Replayed' => 'true'])]);

    app(SubmitGeneration::class)->handle($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_nanos->toDecimal())->toBe('0.070687500')
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('0.929312500');
});

it('marks the generation as failed when the job gives up', function () {
    $generation = Generation::factory()->create();

    (new SubmitGenerationToPixelforge($generation))->failed(new RuntimeException('timed out'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submission_abandoned')
        ->and($generation->charged_nanos->isZero())->toBeTrue();
});
