<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteGenerationRequest;
use App\Services\Billing\CostEstimator;
use App\Services\Billing\PricingRepository;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Http\JsonResponse;

/**
 * Live price estimate for the generate form. The same estimator decides the credit hold
 * on submit, so the price shown is exactly the price used.
 */
class QuoteController extends Controller
{
    public function __invoke(QuoteGenerationRequest $request, PricingRepository $pricing, CostEstimator $estimator): JsonResponse
    {
        try {
            $currentPricing = $pricing->current();
        } catch (PixelforgeException $e) {
            report($e);

            return response()->json(['message' => 'Live pricing is temporarily unavailable.'], 503);
        }

        $input = $request->toInput();

        $estimate = $estimator->estimate($input->prompt, $input->size, $input->quality, count($input->referenceImages), $currentPricing);

        $available = $request->user()->availableBalance();

        return response()->json([
            ...$estimate->toArray(),
            'available' => $available->toDecimal(),
            'affordable' => ! $available->lessThan($estimate->maxCharge),
        ]);
    }
}
