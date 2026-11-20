<?php

namespace App\Actions\Generations;

use App\Data\GenerationInput;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostEstimator;
use App\Services\Billing\PricingRepository;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Support\Facades\DB;

/**
 * Reserves credit for a new generation and queues it for submission to Pixelforge.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly CostEstimator $estimator,
    ) {}

    /**
     * @throws InsufficientCreditsException
     * @throws PixelforgeException when pricing is unavailable
     */
    public function handle(User $user, GenerationInput $input): Generation
    {
        $pricing = $this->pricing->current();

        $estimate = $this->estimator->estimate($input->prompt, $input->size, $input->quality, count($input->referenceImages), $pricing);

        $generation = DB::transaction(function () use ($user, $input, $estimate, $pricing) {
            // Serialise spending per user so two tabs cannot both spend the same credit.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $duplicate = $input->clientRequestId === null
                ? null
                : $user->generations()->where('client_request_id', $input->clientRequestId)->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            $available = $user->availableBalance();

            if ($available->lessThan($estimate->maxCharge)) {
                throw new InsufficientCreditsException($available, $estimate->maxCharge);
            }

            return $user->generations()->create([
                'client_request_id' => $input->clientRequestId,
                'status' => GenerationStatus::Pending,
                'prompt' => $input->prompt,
                'size' => $input->size,
                'quality' => $input->quality,
                'reference_images' => $input->referenceImages,
                'estimated_min_charge_nanos' => $estimate->minCharge,
                'estimated_max_charge_nanos' => $estimate->maxCharge,
                // Replaced by the rates Pixelforge fixes at creation once it accepts the generation.
                'rates' => $pricing->rates->toArray(),
            ]);
        });

        if ($generation->wasRecentlyCreated) {
            SubmitGenerationToPixelforge::dispatch($generation);
        }

        return $generation;
    }
}
