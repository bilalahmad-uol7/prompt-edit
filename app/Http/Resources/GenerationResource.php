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
     * Failures that a plain retry will not fix.
     */
    private const array PERMANENT_FAILURES = ['content_policy_violation', 'validation_failed'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'waiting_reason' => $this->waitingReason(),
            'error' => $this->status === GenerationStatus::Failed ? [
                'code' => $this->error_code,
                'message' => $this->error_message ?? PixelforgeErrorCode::tryFrom((string) $this->error_code)?->userMessage(),
            ] : null,
            'can_retry' => $this->status === GenerationStatus::Failed && ! in_array($this->error_code, self::PERMANENT_FAILURES, true),
            'image_url' => $this->displayImageUrl(),
            'image_expired' => $this->imageHasExpired(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    private function waitingReason(): ?string
    {
        if ($this->status !== GenerationStatus::Pending || $this->error_code === null) {
            return null;
        }

        return match ($this->error_code) {
            'concurrency_limit_exceeded' => 'Waiting for a free slot at the provider',
            'rate_limit_exceeded' => 'Provider rate limit reached — retrying shortly',
            'overloaded' => 'Provider overloaded — retrying shortly',
            default => 'Provider not responding — retrying shortly',
        };
    }
}
