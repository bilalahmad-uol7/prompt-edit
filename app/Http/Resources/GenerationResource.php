<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $errorCode = PixelforgeErrorCode::tryFrom((string) $this->error_code);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_min_charge' => $this->estimated_min_charge_nanos->toDecimal(),
            'estimated_max_charge' => $this->estimated_max_charge_nanos->toDecimal(),
            'charged' => $this->charged_nanos?->toDecimal(),
            'tokens' => $this->tokenUsage()?->toArray(),
            'waiting_reason' => $this->waitingReason($errorCode),
            'error' => $this->status === GenerationStatus::Failed ? [
                'code' => $this->error_code,
                'message' => $this->error_message ?? $errorCode?->userMessage(),
            ] : null,
            'can_retry' => $this->status === GenerationStatus::Failed && ! $errorCode?->isPermanent(),
            'image_url' => $this->displayImageUrl(),
            'image_expired' => $this->imageHasExpired(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Why a generation that Pixelforge has not accepted yet is still waiting, if a retry is scheduled.
     */
    private function waitingReason(?PixelforgeErrorCode $errorCode): ?string
    {
        if ($this->status !== GenerationStatus::Pending || $this->error_code === null) {
            return null;
        }

        return match ($errorCode) {
            PixelforgeErrorCode::ConcurrencyLimitExceeded => 'Waiting for a free slot at the provider',
            PixelforgeErrorCode::RateLimitExceeded => 'Provider rate limit reached — retrying shortly',
            PixelforgeErrorCode::Overloaded => 'Provider overloaded — retrying shortly',
            default => 'Provider not responding — retrying shortly',
        };
    }
}
