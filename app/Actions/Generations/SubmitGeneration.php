<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
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

    /**
     * Transient failures are retried later; anything else fails the generation for free.
     *
     * @return int|null seconds to wait before retrying, or null when the generation was failed
     */
    private function handleFailure(Generation $generation, PixelforgeException $e): ?int
    {
        $context = ['generation' => $generation->id, 'attempt' => $generation->submit_attempts, 'reason' => $e->getMessage()];

        if ($e->isRetryable()) {
            $delay = $this->backoff($generation->submit_attempts, $e->retryAfter());

            // The reason is shown to the user while they wait (e.g. "Waiting for a free slot").
            $generation->update(['error_code' => $e->reason(), 'retry_at' => now()->addSeconds($delay)]);
            Log::info('Pixelforge submission deferred', [...$context, 'retry_in' => $delay]);

            return $delay;
        }

        $e->indicatesIntegrationProblem()
            ? Log::critical('Pixelforge rejected a submission because of our integration', $context)
            : Log::warning('Pixelforge rejected a submission', $context);

        $this->fail->handle($generation, code: $e->reason(), message: $e->userMessage());

        return null;
    }

    /**
     * Respect Retry-After, back off further on each attempt (capped), and add jitter so
     * waiting generations do not all retry in the same second.
     */
    private function backoff(int $attempt, int $retryAfter): int
    {
        $exponential = min(self::MAX_BACKOFF_SECONDS, 2 ** max(0, $attempt - 1));

        return max($retryAfter, $exponential) + random_int(0, 2);
    }
}
