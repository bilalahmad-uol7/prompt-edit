<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
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
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, client_request_id?: string|null}  $input
     *
     * @throws InsufficientCreditsException
     * @throws PixelforgeException when pricing is unavailable
     */
    public function handle(User $user, array $input): Generation
    {
        $pricing = $this->pricing->current();
        $size = ImageSize::from($input['size']);
        $quality = ImageQuality::from($input['quality']);
        $referenceImages = array_values($input['reference_images'] ?? []);
        $clientRequestId = $input['client_request_id'] ?? null;

        $estimate = $this->estimator->estimate($input['prompt'], $size, $quality, count($referenceImages), $pricing);

        $generation = DB::transaction(function () use ($user, $input, $size, $quality, $referenceImages, $clientRequestId, $estimate, $pricing) {
            // Serialise spending per user so two tabs cannot both spend the same credit.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $duplicate = $clientRequestId === null ? null : $user->generations()->where('client_request_id', $clientRequestId)->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            $available = $user->availableBalance();

            if ($available->lessThan($estimate->maxCharge)) {
                throw new InsufficientCreditsException($available, $estimate->maxCharge);
            }

            return $user->generations()->create([
                'client_request_id' => $clientRequestId,
                'status' => GenerationStatus::Pending,
                'prompt' => $input['prompt'],
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
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
