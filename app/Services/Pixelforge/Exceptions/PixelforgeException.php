<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Any failed call to Pixelforge. Callers decide what to do through this interface
 * alone, without checking which concrete failure it was.
 */
abstract class PixelforgeException extends RuntimeException
{
    /**
     * Whether the same request may succeed if retried later.
     */
    abstract public function isRetryable(): bool;

    /**
     * Seconds to wait before retrying.
     */
    abstract public function retryAfter(): int;

    /**
     * Short machine-readable reason, stored on the generation (e.g. "overloaded").
     */
    abstract public function reason(): string;

    /**
     * Message safe to show to the end user.
     */
    abstract public function userMessage(): string;

    /**
     * True when the failure points at a bug or misconfiguration on our side
     * (bad API key, reused idempotency key, ...) rather than at the request itself.
     */
    public function indicatesIntegrationProblem(): bool
    {
        return false;
    }
}
