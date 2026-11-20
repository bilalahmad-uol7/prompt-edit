<?php

namespace App\Services\Pixelforge\Exceptions;

use App\Services\Pixelforge\PixelforgeErrorCode;
use Illuminate\Http\Client\Response;

/**
 * Pixelforge answered with a non-2xx status.
 */
class PixelforgeRequestException extends PixelforgeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly ?PixelforgeErrorCode $errorCode,
        public readonly string $rawCode,
        string $message,
        public readonly ?int $retryAfterHeader = null,
        public readonly array $errors = [],
    ) {
        parent::__construct("Pixelforge {$status} {$rawCode}: {$message}");
    }

    public static function fromResponse(Response $response): self
    {
        $error = (array) $response->json('error', []);
        $rawCode = (string) ($error['code'] ?? 'http_'.$response->status());
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: PixelforgeErrorCode::tryFrom($rawCode),
            rawCode: $rawCode,
            message: (string) ($error['message'] ?? $response->reason()),
            retryAfterHeader: is_numeric($retryAfter) ? (int) $retryAfter : null,
            errors: (array) ($error['errors'] ?? []),
        );
    }

    public function isRetryable(): bool
    {
        if ($this->errorCode !== null) {
            return $this->errorCode->isRetryable();
        }

        // Undocumented server-side errors (502, 504, ...) are treated as transient.
        return $this->status >= 500 || $this->status === 408;
    }

    public function retryAfter(): int
    {
        return $this->retryAfterHeader ?? $this->errorCode?->defaultRetryAfter() ?? 5;
    }

    public function reason(): string
    {
        return $this->rawCode;
    }

    public function userMessage(): string
    {
        return $this->errorCode?->userMessage() ?? 'The image provider rejected the request. You were not charged.';
    }

    public function indicatesIntegrationProblem(): bool
    {
        return $this->errorCode === null || $this->errorCode->indicatesIntegrationProblem();
    }
}
