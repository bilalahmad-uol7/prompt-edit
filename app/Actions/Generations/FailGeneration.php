<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\DB;

/**
 * Marks a generation that never got accepted by Pixelforge as failed. Nothing is charged
 * and, because it is no longer in flight, its credit hold is released.
 */
class FailGeneration
{
    public function handle(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->status->isTerminal()) {
                return;
            }

            $locked->update([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost_nanos' => Usd::zero(),
                'charged_nanos' => Usd::zero(),
                'retry_at' => null,
                'completed_at' => now(),
            ]);
        });

        $generation->refresh();
    }
}
