<?php

namespace App\Jobs;

use App\Actions\Generations\FailGeneration;
use App\Actions\Generations\SubmitGeneration;
use App\Models\Generation;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Gets a pending generation accepted by Pixelforge, waiting out rate limits, the
 * concurrency limit and overloads. Gives up after a time limit, not an attempt count,
 * because each wait for capacity is a release, not an error.
 */
class SubmitGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('pixelforge'),
            (new WithoutOverlapping($this->generation->id))->releaseAfter(5)->expireAfter(60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(config('pixelforge.submission.give_up_after_minutes'));
    }

    public function handle(SubmitGeneration $submit): void
    {
        $retryIn = $submit->handle($this->generation);

        if ($retryIn !== null) {
            $this->release($retryIn);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FailGeneration::class)->handle(
            $this->generation,
            code: 'submission_abandoned',
            message: 'The image provider stayed busy for too long, so we stopped trying. You were not charged.',
        );
    }
}
