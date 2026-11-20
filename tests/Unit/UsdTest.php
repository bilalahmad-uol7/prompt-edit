<?php

use App\Support\Money\Usd;
use Brick\Math\Exception\RoundingNecessaryException;

it('parses exact decimal strings into nanodollars', function () {
    expect(Usd::of('0.046125000')->nanos)->toBe(46_125_000)
        ->and(Usd::of('5')->nanos)->toBe(5_000_000_000)
        ->and(Usd::of('0.000000001')->nanos)->toBe(1);
});

it('refuses to silently lose precision', function () {
    Usd::of('0.0000000001');
})->throws(RoundingNecessaryException::class);

it('formats back to a nine decimal string', function () {
    expect(Usd::fromNanos(373_875_000)->toDecimal())->toBe('0.373875000')
        ->and(Usd::fromNanos(-1)->toDecimal())->toBe('-0.000000001')
        ->and((string) Usd::fromNanos(5_000_000_000))->toBe('5.000000000');
});

it('applies a markup exactly when divisible', function () {
    expect(Usd::of('0.373875000')->withMarkup(25)->toDecimal())->toBe('0.467343750');
});

it('rounds sub-nanodollar markup remainders up, never down', function () {
    // 1 nanodollar * 1.25 = 1.25 nanodollars -> 2
    expect(Usd::fromNanos(1)->withMarkup(25)->nanos)->toBe(2)
        ->and(Usd::fromNanos(3)->withMarkup(25)->nanos)->toBe(4)
        ->and(Usd::fromNanos(4)->withMarkup(25)->nanos)->toBe(5)
        ->and(Usd::zero()->withMarkup(25)->nanos)->toBe(0);
});

it('does arithmetic and comparisons', function () {
    $a = Usd::of('1.5');
    $b = Usd::of('0.25');

    expect($a->plus($b)->toDecimal())->toBe('1.750000000')
        ->and($a->minus($b)->toDecimal())->toBe('1.250000000')
        ->and($b->minus($a)->isNegative())->toBeTrue()
        ->and($a->greaterThan($b))->toBeTrue()
        ->and($b->lessThan($a))->toBeTrue()
        ->and($a->equals(Usd::of('1.500000000')))->toBeTrue();
});

it('formats for humans', function () {
    expect(Usd::of('0.46734375')->format())->toBe('$0.4673')
        ->and(Usd::of('5')->format(2))->toBe('$5.00')
        ->and(Usd::of('-0.1')->format(2))->toBe('-$0.10');
});
