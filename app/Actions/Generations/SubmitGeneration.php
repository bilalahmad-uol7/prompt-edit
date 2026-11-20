<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeConnectionException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeRequestException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * Sends a pending generation to Pixelforge, once.
 *
 * The local ULID is used as the Idempotency-Key, so retries after a timeout or
 * overload can never create (or bill) a second generation at Pixelforge.
 */
class SubmitGeneration
{
    private const int MAX_BACKOFF_SECONDS = 60;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly SyncGenerationFromProvider $sync,
        private readonly FailGeneration $fail,
    ) {}

    /**
     * @return int|null seconds to wait before trying again, or null when there is nothing left to do
     */
    public function handle(Generation $generation): ?int
    {
        $generation->refresh();

        // A webhook or the reconciler may already know about it.
        if ($generation->status !== GenerationStatus::Pending) {
            return null;
        }

        $generation->increment('submit_attempts');

        try {
            $accepted = $this->client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                // Lets webhooks find the generation even if they arrive before this request returns.
                'metadata' => ['local_id' => $generation->id],
            ], idempotencyKey: $generation->id);
        } catch (PixelforgeException $e) {
            return $this->handleFailure($generation, $e);
        }

        $this->sync->handle($accepted, $generation);

        return null;
    }

    private function handleFailure(Generation $generation, PixelforgeException $e): ?int
    {
        if ($e->isRetryable()) {
            $delay = $this->backoff($generation, $e);

            $generation->update([
                'error_code' => $e instanceof PixelforgeRequestException ? $e->rawCode : 'provider_unreachable',
                'retry_at' => now()->addSeconds($delay),
            ]);

            Log::info('Pixelforge submission deferred', [
                'generation' => $generation->id,
                'attempt' => $generation->submit_attempts,
                'retry_in' => $delay,
                'reason' => $e->getMessage(),
            ]);

            return $delay;
        }

        $context = ['generation' => $generation->id, 'reason' => $e->getMessage()];

        // These mean our integration is misconfigured or buggy, not that the user did something wrong.
        if ($e instanceof PixelforgeRequestException && in_array($e->errorCode, [PixelforgeErrorCode::InvalidApiKey, PixelforgeErrorCode::IdempotencyKeyReused, null], true)) {
            Log::critical('Pixelforge rejected a submission unexpectedly', $context);
        } else {
            Log::warning('Pixelforge rejected a submission', $context);
        }

        $this->fail->handle(
            $generation,
            code: $e instanceof PixelforgeRequestException ? $e->rawCode : 'provider_unreachable',
            message: $e instanceof PixelforgeRequestException ? $e->userMessage() : 'Could not reach the image provider. You were not charged.',
        );

        return null;
    }

    /**
     * Respect Retry-After, then back off further on each attempt, with jitter so queued
     * generations do not all retry in the same second.
     */
    private function backoff(Generation $generation, PixelforgeException $e): int
    {
        $exponential = min(self::MAX_BACKOFF_SECONDS, 2 ** max(0, $generation->submit_attempts - 1));
        $minimum = $e instanceof PixelforgeConnectionException ? $exponential : max($e->retryAfter(), $exponential);

        return $minimum + random_int(0, 2);
    }
}
