<?php

namespace App\Data;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;

/**
 * What a user asked to generate, already validated.
 */
final readonly class GenerationInput
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages = [],
        public ?string $clientRequestId = null,
    ) {}

    /**
     * The same settings as an earlier generation, e.g. to retry it.
     */
    public static function from(Generation $generation, ?string $clientRequestId = null): self
    {
        return new self(
            prompt: $generation->prompt,
            size: $generation->size,
            quality: $generation->quality,
            referenceImages: $generation->reference_images,
            clientRequestId: $clientRequestId,
        );
    }
}
