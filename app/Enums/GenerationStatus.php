<?php

namespace App\Enums;

/**
 * Lifecycle of a generation. "Pending" is ours: the generation exists locally but
 * Pixelforge has not accepted it yet (waiting in our queue, or waiting for capacity).
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Position in the lifecycle. A generation only ever moves to a higher rank,
     * which makes out-of-order webhook delivery harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isTerminal() && $next->rank() > $this->rank();
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
