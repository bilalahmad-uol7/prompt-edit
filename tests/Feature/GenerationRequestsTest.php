<?php

use App\Enums\GenerationStatus;
use App\Exceptions\PricingUnavailableException;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->pricingAvailable = true;
    Http::fake(['*/pricing' => fn () => $this->pricingAvailable
        ? Http::response(pixelforgeFixture('pricing'))
        : Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);
});

/**
 * @return array<string, mixed>
 */
function generationInput(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'client_request_id' => (string) Str::uuid(),
    ], $overrides);
}

it('keeps guests out', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->post(route('generations.store'), generationInput())->assertRedirect(route('login'));
});

it('renders the generate page', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->has('sizes', 3)
            ->has('qualities', 3)
            ->where('pricingError', null)
            ->where('credits.available', '1.000000000'));
});

it('queues a generation and holds its maximum possible cost', function () {
    $user = User::factory()->withBalance('1.00')->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationInput(['quality' => 'high', 'size' => '1536x1024', 'prompt' => str_repeat('a', 400), 'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']]))
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasNoErrors();

    $generation = Generation::sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->estimated_max_charge_nanos->toDecimal())->toBe('0.467343750')
        ->and($user->availableBalance()->toDecimal())->toBe('0.532656250')
        ->and($user->refresh()->balance_nanos->toDecimal())->toBe('1.000000000');

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generation->is($generation));
});

it('ignores empty reference image rows', function () {
    $user = User::factory()->withBalance('1.00')->create();

    $this->actingAs($user)->post(route('generations.store'), generationInput(['reference_images' => ['', '  ', 'https://example.com/a.png']]));

    expect(Generation::sole()->reference_images)->toBe(['https://example.com/a.png']);
});

it('validates input like Pixelforge does', function (array $input, string $field) {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->post(route('generations.store'), generationInput($input))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
})->with([
    'missing prompt' => [['prompt' => '   '], 'prompt'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
    'non http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'duplicate references' => [['reference_images' => ['https://example.com/a.png', 'https://example.com/a.png']], 'reference_images.0'],
]);

it('accepts a 4000 character prompt counted in unicode characters', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->post(route('generations.store'), generationInput(['prompt' => str_repeat('🎨', 4000)]))
        ->assertSessionHasNoErrors();
});

it('blocks generating when the maximum cost exceeds available credit', function () {
    $user = User::factory()->withBalance('0.05')->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationInput())
        ->assertInertiaFlash('toast.type', 'error');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('counts credit held by running generations', function () {
    // Medium 1024x1024 with a short prompt holds up to ~$0.086 each.
    $user = User::factory()->withBalance('0.10')->create();

    $this->actingAs($user)->post(route('generations.store'), generationInput())->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('generations.store'), generationInput())->assertInertiaFlash('toast.type', 'error');

    expect(Generation::count())->toBe(1);
});

it('creates one generation when the same form is submitted twice', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $input = generationInput();

    $this->actingAs($user)->post(route('generations.store'), $input);
    $this->actingAs($user)->post(route('generations.store'), $input);

    expect(Generation::count())->toBe(1);
    Queue::assertPushed(SubmitGenerationToPixelforge::class, 1);
});

it('pauses generating when pricing cannot be loaded', function () {
    $this->pricingAvailable = false;
    $user = User::factory()->withBalance('1.00')->create();

    $this->actingAs($user)->post(route('generations.store'), generationInput())->assertInertiaFlash('toast.message', PricingUnavailableException::MESSAGE);
    $this->actingAs($user)->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page->whereType('pricingError', 'string'));

    expect(Generation::count())->toBe(0);
});

it('quotes the price range and affordability', function () {
    $this->actingAs(User::factory()->withBalance('0.10')->create())
        ->postJson(route('generations.quote'), ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']])
        ->assertOk()
        ->assertJson([
            'max_charge' => '0.467343750',
            'available' => '0.100000000',
            'affordable' => false,
            'max_tokens' => ['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000],
        ]);
});

it('answers the quote endpoint with 503 when pricing is unavailable', function () {
    $this->pricingAvailable = false;

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.quote'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertStatus(503)
        ->assertJsonPath('message', PricingUnavailableException::MESSAGE);
});

it('quotes an empty prompt while the user is still typing', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->postJson(route('generations.quote'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonPath('affordable', true);
});

it('shows users only their own history', function () {
    $user = User::factory()->create();
    Generation::factory()->count(2)->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 3)
            ->where('hasInFlight', false));

    $this->actingAs($user)->get(route('generations.index', ['status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->has('generations.data', 1)->where('generations.data.0.status', 'failed'));
});

it('exposes costs and status to the history page', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->create(['error_code' => 'concurrency_limit_exceeded']);

    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasInFlight', true)
            ->where('generations.data.0.waiting_reason', 'Waiting for a free slot at the provider')
            ->where('generations.data.1.charged', '0.070687500')
            ->where('generations.data.1.tokens.output_tokens', 1100));
});

it('retries a failed generation once, with the same settings', function () {
    $user = User::factory()->withBalance('1.00')->create();
    $failed = Generation::factory()->for($user)->failed('internal_error')->create(['quality' => 'low']);

    $this->actingAs($user)->post(route('generations.retry', $failed))->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('generations.retry', $failed));

    $retry = Generation::query()->whereKeyNot($failed->id)->sole();
    expect($retry->status)->toBe(GenerationStatus::Pending)
        ->and($retry->prompt)->toBe($failed->prompt)
        ->and($retry->quality->value)->toBe('low');
});

it('tells the user when a retry cannot be afforded', function () {
    $user = User::factory()->withBalance('0.01')->create();
    $failed = Generation::factory()->for($user)->failed('internal_error')->create();

    $this->actingAs($user)
        ->post(route('generations.retry', $failed))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(Generation::count())->toBe(1);
});

it('does not let users retry generations that are not theirs or not failed', function () {
    $user = User::factory()->withBalance('1.00')->create();

    $this->actingAs($user)->post(route('generations.retry', Generation::factory()->failed()->create()))->assertForbidden();
    $this->actingAs($user)->post(route('generations.retry', Generation::factory()->for($user)->succeeded()->create()))->assertStatus(422);
});
