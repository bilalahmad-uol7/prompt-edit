<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a finished image to our own storage: Pixelforge URLs expire 24h after completion.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Generation $generation) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(): void
    {
        $generation = $this->generation->refresh();

        if ($generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        if ($generation->image_url_expires_at?->isPast()) {
            Log::warning('Generation image expired before it could be stored', ['generation' => $generation->id]);

            return;
        }

        $response = Http::timeout(30)->get($generation->image_url)->throw();

        $path = "generations/{$generation->id}.png";
        Storage::disk(config('pixelforge.images.disk'))->put($path, $response->body());

        $generation->update(['image_path' => $path]);
    }
}
