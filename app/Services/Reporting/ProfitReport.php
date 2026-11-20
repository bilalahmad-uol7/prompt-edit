<?php

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Data\UsageDay;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;

/**
 * Per UTC day and per user: what users were charged, what Pixelforge charged us, and the profit.
 *
 * Days are bucketed by completion time, because Pixelforge bills a generation on the UTC day
 * it completed. That makes our per-day provider cost directly comparable to GET /v1/usage.
 *
 * @phpstan-type UserRow array{user_id: int|null, name: string, email: string|null, generations: int, charged: Usd, provider_cost: Usd}
 */
class ProfitReport
{
    /**
     * @param  array<string, UsageDay>|null  $providerUsage  GET /v1/usage keyed by date, null when unavailable
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?array $providerUsage): array
    {
        $rowsByDate = $this->ourRowsByDate($from, $to);
        $days = [];
        $totalCharged = Usd::zero();
        $totalProviderCost = Usd::zero();
        $totalGenerations = 0;

        for ($date = $to->startOfDay(); $date->gte($from->startOfDay()); $date = $date->subDay()) {
            $key = $date->toDateString();
            $users = $rowsByDate[$key] ?? [];

            $charged = array_reduce($users, fn (Usd $sum, array $row) => $sum->plus($row['charged']), Usd::zero());
            $providerCost = array_reduce($users, fn (Usd $sum, array $row) => $sum->plus($row['provider_cost']), Usd::zero());
            $generations = array_sum(array_column($users, 'generations'));
            $billed = $providerUsage[$key] ?? null;

            $days[] = [
                'date' => $key,
                'generations' => $generations,
                'charged' => $charged->toDecimal(),
                'provider_cost' => $providerCost->toDecimal(),
                'profit' => $charged->minus($providerCost)->toDecimal(),
                'pixelforge' => $billed === null ? null : [
                    'generations' => $billed->generations,
                    'cost' => $billed->cost->toDecimal(),
                    'matches' => $billed->cost->equals($providerCost) && $billed->generations === $generations,
                ],
                'users' => array_map(fn (array $row) => [
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'generations' => $row['generations'],
                    'charged' => $row['charged']->toDecimal(),
                    'provider_cost' => $row['provider_cost']->toDecimal(),
                    'profit' => $row['charged']->minus($row['provider_cost'])->toDecimal(),
                ], $users),
            ];

            $totalCharged = $totalCharged->plus($charged);
            $totalProviderCost = $totalProviderCost->plus($providerCost);
            $totalGenerations += $generations;
        }

        $pixelforgeCost = $providerUsage === null
            ? null
            : array_reduce($providerUsage, fn (Usd $sum, UsageDay $day) => $sum->plus($day->cost), Usd::zero());

        return [
            'days' => $days,
            'totals' => [
                'generations' => $totalGenerations,
                'charged' => $totalCharged->toDecimal(),
                'provider_cost' => $totalProviderCost->toDecimal(),
                'profit' => $totalCharged->minus($totalProviderCost)->toDecimal(),
                'pixelforge_cost' => $pixelforgeCost?->toDecimal(),
            ],
        ];
    }

    /**
     * Succeeded generations summed per completion day and user, biggest spenders first.
     *
     * @return array<string, list<UserRow>>
     */
    private function ourRowsByDate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Generation::query()
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded)
            ->whereBetween('generations.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupByRaw('DATE(generations.completed_at), generations.user_id, users.name, users.email')
            ->orderByRaw('SUM(generations.charged_nanos) DESC')
            ->selectRaw('DATE(generations.completed_at) AS completed_on')
            ->selectRaw('generations.user_id, users.name, users.email')
            ->selectRaw('COUNT(*) AS generation_count')
            ->selectRaw('SUM(generations.charged_nanos) AS charged_nanos')
            ->selectRaw('SUM(generations.provider_cost_nanos) AS provider_cost_nanos')
            ->toBase()
            ->get();

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[CarbonImmutable::parse($row->completed_on)->toDateString()][] = [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'name' => $row->name ?? 'Deleted account',
                'email' => $row->email,
                'generations' => (int) $row->generation_count,
                'charged' => Usd::fromNanos((int) $row->charged_nanos),
                'provider_cost' => Usd::fromNanos((int) $row->provider_cost_nanos),
            ];
        }

        return $byDate;
    }
}
