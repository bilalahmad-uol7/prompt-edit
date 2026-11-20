<?php

namespace App\Http\Controllers;

use App\Actions\Generations\SyncGenerationFromProvider;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\Data\GenerationData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhook events (signature verified by middleware).
 *
 * Delivery is at least once and in any order: duplicates are dropped by event id and
 * ordering is handled by the generation state machine. The state change is quick DB work,
 * so it happens before we answer: if it fails we return 5xx and Pixelforge retries.
 * The slow part (downloading the image) is queued.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, SyncGenerationFromProvider $sync): Response
    {
        $payload = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'data.object' => ['nullable', 'array'],
        ]);

        $event = WebhookEvent::query()->createOrFirst(['event_id' => $payload['id']], [
            'type' => $payload['type'],
            'provider_generation_id' => $request->input('data.object.id'),
            'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', '1'),
            'payload' => $request->json()->all(),
        ]);

        if ($event->processed_at !== null) {
            return response()->noContent();
        }

        if (str_starts_with($event->type, 'generation.') && is_array($request->input('data.object'))) {
            $sync->handle(GenerationData::fromArray($request->input('data.object')));
        } else {
            Log::info('Ignoring Pixelforge webhook event', ['event' => $event->event_id, 'type' => $event->type]);
        }

        $event->update(['processed_at' => now()]);

        return response()->noContent();
    }
}
