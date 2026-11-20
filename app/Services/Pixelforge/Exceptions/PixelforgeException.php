<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

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
}
