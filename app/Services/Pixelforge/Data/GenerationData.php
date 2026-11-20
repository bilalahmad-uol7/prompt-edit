<?php

namespace App\Services\Pixelforge\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * A Pixelforge generation object, as returned by the API or embedded in a webhook event.
 */
final readonly class GenerationData
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata,
        public ?TokenRates $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: $payload['id'],
            status: GenerationStatus::from($payload['status']),
            metadata: (array) ($payload['metadata'] ?? []),
            rates: isset($payload['rates']) ? TokenRates::fromArray($payload['rates']) : null,
            usage: isset($payload['usage']) ? TokenUsage::fromArray($payload['usage']) : null,
            imageUrl: $payload['image_url'] ?? null,
            imageUrlExpiresAt: self::date($payload['image_url_expires_at'] ?? null),
            errorCode: $payload['error']['code'] ?? null,
            errorMessage: $payload['error']['message'] ?? null,
            startedAt: self::date($payload['started_at'] ?? null),
            completedAt: self::date($payload['completed_at'] ?? null),
        );
    }

    /**
     * The local generation id we attached as metadata when creating it.
     */
    public function localId(): ?string
    {
        return $this->metadata['local_id'] ?? null;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
