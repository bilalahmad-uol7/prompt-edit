<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every webhook event we accepted, keyed by Pixelforge's event id.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 */
#[Fillable(['event_id', 'type', 'provider_generation_id', 'delivery_attempt', 'payload', 'processed_at'])]
class WebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
