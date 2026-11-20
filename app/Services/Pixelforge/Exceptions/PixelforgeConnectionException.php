<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * Pixelforge could not be reached, or did not answer in time.
 *
 * For a create request the outcome is unknown: it may or may not have been accepted.
 * Retrying with the same Idempotency-Key resolves that safely.
 */
class PixelforgeConnectionException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 5;
    }
}
