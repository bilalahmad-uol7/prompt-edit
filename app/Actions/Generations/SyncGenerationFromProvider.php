<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Billing\CostCalculator;
use App\Services\Billing\CreditLedger;
use App\Services\Pixelforge\Data\GenerationData;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Applies what Pixelforge says about a generation to our record. This is the one
 * state machine used by webhooks, submission responses and reconciliation polling.
 *
 * Safe to call any number of times, in any order, concurrently:
 *  - the generation row is locked for the duration,
 *  - a generation only moves forward (queued → processing → succeeded/failed),
 *  - a final state is never left, so it is settled (charged) exactly once.
 */
class SyncGenerationFromProvider
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  Generation|null  $knownGeneration  the local record, when the caller already knows it (e.g. right after submitting)
     */
    public function handle(GenerationData $data, ?Generation $knownGeneration = null): ?Generation
    {
        return DB::transaction(function () use ($data, $knownGeneration) {
            $generation = $this->findAndLock($data, $knownGeneration->id ?? $data->localId());

            if ($generation === null) {
                Log::warning('Pixelforge reported an unknown generation', ['provider_id' => $data->id, 'local_id' => $data->localId()]);

                return null;
            }

            $wasRejectedLocally = $generation->status === GenerationStatus::Failed && $generation->provider_id === null;

            $generation->provider_id ??= $data->id;
            $generation->rates = $data->rates?->toArray() ?? $generation->rates;
            $generation->last_synced_at = now();

            if ($wasRejectedLocally) {
                $this->adoptAfterLocalFailure($generation, $data);
            } elseif ($generation->status->canTransitionTo($data->status)) {
                $this->transition($generation, $data);
            }

            $generation->save();

            return $generation;
        });
    }

    private function findAndLock(GenerationData $data, ?string $localId): ?Generation
    {
        $generation = Generation::query()
            ->where('provider_id', $data->id)
            ->when($localId, fn ($query, string $localId) => $query->orWhere('id', $localId))
            ->lockForUpdate()
            ->first();

        if ($generation?->provider_id !== null && $generation->provider_id !== $data->id) {
            Log::error('Pixelforge generation id does not match our record', ['generation' => $generation->id, 'provider_id' => $data->id]);

            return null;
        }

        return $generation;
    }

    private function transition(Generation $generation, GenerationData $data): void
    {
        $generation->status = $data->status;
        $generation->submitted_at ??= now();
        $generation->retry_at = null;
        $generation->error_code = null;

        match ($data->status) {
            GenerationStatus::Processing => $generation->started_at = $data->startedAt ?? now(),
            GenerationStatus::Succeeded => $this->settleSuccess($generation, $data, chargeUser: true),
            GenerationStatus::Failed => $this->settleFailure($generation, $data),
            default => null,
        };
    }

    private function settleSuccess(Generation $generation, GenerationData $data, bool $chargeUser): void
    {
        $usage = $data->usage ?? throw new UnexpectedValueException("Generation {$data->id} succeeded without usage.");
        $rates = $data->rates ?? $generation->tokenRates() ?? throw new UnexpectedValueException("Generation {$data->id} has no rates.");

        $providerCost = $this->calculator->providerCost($usage, $rates);
        $charge = $chargeUser ? $this->calculator->userCharge($providerCost) : Usd::zero();

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => $charge,
            'image_url' => $data->imageUrl,
            'image_url_expires_at' => $data->imageUrlExpiresAt,
            // Pixelforge bills on the UTC day a generation completed; keep its timestamp for reconciliation.
            'completed_at' => $data->completedAt ?? now(),
        ]);
        $generation->started_at ??= $data->startedAt;

        $this->ledger->chargeForGeneration($generation, $charge);

        StoreGenerationImage::dispatch($generation)->afterCommit();
    }

    private function settleFailure(Generation $generation, GenerationData $data): void
    {
        $generation->fill([
            'error_code' => $data->errorCode ?? 'generation_failed',
            'error_message' => $data->errorMessage,
            'provider_cost_nanos' => Usd::zero(),
            'charged_nanos' => Usd::zero(),
            'completed_at' => $data->completedAt ?? now(),
        ]);
    }

    /**
     * We gave up submitting (e.g. every attempt timed out) and told the user it failed, yet
     * Pixelforge did accept one of the attempts. Record what Pixelforge bills us so provider
     * costs still reconcile, but keep our promise: the user gets the image without being charged.
     */
    private function adoptAfterLocalFailure(Generation $generation, GenerationData $data): void
    {
        if ($data->status !== GenerationStatus::Succeeded) {
            return;
        }

        Log::warning('Pixelforge completed a generation we had marked as failed', ['generation' => $generation->id]);

        $generation->error_code = null;
        $generation->error_message = null;
        $this->settleSuccess($generation, $data, chargeUser: false);
    }
}
