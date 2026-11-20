<?php

namespace App\Console\Commands;

use App\Actions\Generations\SyncGenerationFromProvider;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeRequestException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for lost webhooks and lost queue jobs. Webhook delivery is not guaranteed,
 * so in-flight generations that have been quiet for a while are polled directly.
 */
#[Signature('generations:reconcile {--limit=30 : Maximum generations to poll per run}')]
#[Description('Poll Pixelforge for stale in-flight generations and re-queue stuck submissions')]
class ReconcileGenerations extends Command
{
    public function handle(PixelforgeClient $client, SyncGenerationFromProvider $sync): int
    {
        $polled = $this->pollStaleGenerations($client, $sync);
        $requeued = $this->requeueStuckSubmissions();

        $this->info("Polled {$polled} stale generation(s), re-queued {$requeued} stuck submission(s).");

        return self::SUCCESS;
    }

    private function pollStaleGenerations(PixelforgeClient $client, SyncGenerationFromProvider $sync): int
    {
        $staleBefore = now()->subSeconds(config('pixelforge.reconcile.stale_after_seconds'));

        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $staleBefore))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $polled = 0;

        foreach ($generations as $generation) {
            try {
                $sync->handle($client->getGeneration($generation->provider_id));
                $polled++;
            } catch (PixelforgeRequestException $e) {
                $this->warn("Could not poll {$generation->id}: {$e->getMessage()}");

                // Out of request budget: try again on the next run.
                if ($e->isRetryable()) {
                    break;
                }
            } catch (PixelforgeException $e) {
                $this->warn("Pixelforge unreachable: {$e->getMessage()}");

                break;
            }
        }

        return $polled;
    }

    /**
     * Pending generations whose submission job vanished (worker crash, deploy) are queued again.
     * Submitting is idempotent, so an accidental duplicate job is harmless.
     */
    private function requeueStuckSubmissions(): int
    {
        $quietSince = now()->subMinutes(2);

        $generations = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', $quietSince)
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<', $quietSince))
            ->get();

        $generations->each(fn (Generation $generation) => SubmitGenerationToPixelforge::dispatch($generation));

        return $generations->count();
    }
}
