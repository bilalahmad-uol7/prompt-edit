<?php

use App\Enums\CreditTransactionType;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('gives new accounts a welcome credit', function () {
    $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::sole();
    expect($user->balance_nanos->toDecimal())->toBe('1.000000000')
        ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::SignupBonus);
});

it('adds credit for a fake purchase and records it in the ledger', function () {
    $user = User::factory()->withBalance('0.50')->create();

    $this->actingAs($user)->post(route('credits.store'), ['amount' => '5.00'])->assertRedirect();

    $transaction = $user->creditTransactions()->sole();
    expect($user->refresh()->balance_nanos->toDecimal())->toBe('5.500000000')
        ->and($transaction->type)->toBe(CreditTransactionType::Purchase)
        ->and($transaction->balance_after_nanos->toDecimal())->toBe('5.500000000');
});

it('only sells the advertised credit packs', function (mixed $amount) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['amount' => $amount])->assertSessionHasErrors('amount');

    expect($user->refresh()->balance_nanos->isZero())->toBeTrue();
})->with(['-5.00', '1000000', '0', 'abc']);

it('shows the credit ledger', function () {
    $user = User::factory()->create();
    app(CreditLedger::class)->credit($user, Usd::of('5'), CreditTransactionType::Purchase);

    $this->actingAs($user->refresh())->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.amount', '5.000000000')
            ->where('credits.balance', '5.000000000'));
});

it('restricts the admin report to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('reports spend, provider cost and profit per day and user, reconciled with Pixelforge usage', function () {
    $today = now('UTC')->toDateString();
    $yesterday = now('UTC')->subDay()->toDateString();

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    Generation::factory()->count(2)->for($alice)->succeeded()->create(['completed_at' => now()]);
    Generation::factory()->for($bob)->succeeded()->create(['completed_at' => now()->subDay()]);
    Generation::factory()->for($bob)->failed()->create(['completed_at' => now()]);

    Http::fake(['*/usage*' => Http::response([
        'object' => 'usage',
        'data' => [
            ['date' => $yesterday, 'generations' => 1, 'cost_usd' => '0.056550000'],
            ['date' => $today, 'generations' => 2, 'cost_usd' => '0.113000000'], // off by $0.0001
        ],
    ])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['from' => $yesterday, 'to' => $today]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.totals.generations', 3)
            ->where('report.totals.charged', '0.212062500')
            ->where('report.totals.provider_cost', '0.169650000')
            ->where('report.totals.profit', '0.042412500')
            ->where('report.days.0.date', $today)
            ->where('report.days.0.users.0.name', 'Alice')
            ->where('report.days.0.users.0.generations', 2)
            ->where('report.days.0.pixelforge.matches', false)
            ->where('report.days.1.date', $yesterday)
            ->where('report.days.1.pixelforge.matches', true));

    Http::assertSent(fn ($request) => str_contains($request->url(), "start_date={$yesterday}") && str_contains($request->url(), "end_date={$today}"));
});

it('still shows our numbers when Pixelforge usage is unavailable', function () {
    Http::fake(['*/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->whereType('usageError', 'string')
            ->has('report.days', 14)
            ->where('report.totals.pixelforge_cost', null));
});
