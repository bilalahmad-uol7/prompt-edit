<?php

namespace App\Services\Pixelforge;

/**
 * Error codes documented by Pixelforge: request errors plus generation failure codes.
 */
enum PixelforgeErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case ValidationFailed = 'validation_failed';
    case ContentPolicyViolation = 'content_policy_violation';
    case NotFound = 'not_found';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case Overloaded = 'overloaded';
    case DateRangeTooLong = 'date_range_too_long';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    /**
     * Transient conditions where retrying the same request later is expected to work.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::Overloaded], true);
    }

    /**
     * Seconds to wait when the response carries no Retry-After header.
     */
    public function defaultRetryAfter(): int
    {
        return match ($this) {
            self::ConcurrencyLimitExceeded => 5,
            self::Overloaded => 2,
            default => 10,
        };
    }

    /**
     * Message safe to show to the end user.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Your prompt was rejected by the content policy. You were not charged.',
            self::ValidationFailed => 'The image provider rejected the request parameters. You were not charged.',
            self::DailyLimitExceeded => 'The daily generation quota has been reached. Please try again tomorrow (UTC). You were not charged.',
            self::GenerationFailed => 'The model could not produce an image for this prompt. You were not charged.',
            self::InternalError => 'The image provider had an internal error. You were not charged — retrying usually works.',
            self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::Overloaded => 'The image provider is busy. We kept retrying but could not submit it. You were not charged.',
            self::InvalidApiKey, self::IdempotencyKeyReused, self::NotFound, self::DateRangeTooLong => 'Something went wrong on our side while submitting. You were not charged.',
        };
    }
}
