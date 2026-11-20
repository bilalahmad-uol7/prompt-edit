<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Data\GenerationInput;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Billing\PricingRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Ramsey\Uuid\Uuid;

/**
 * Insufficient credit and unavailable pricing are domain exceptions that render
 * themselves as a toast (see App\Exceptions), so the actions below stay linear.
 */
class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = GenerationStatus::tryFrom((string) $request->query('status'));

        $generations = $request->user()->generations()
            ->when($status, fn ($query, GenerationStatus $status) => $query->where('status', $status))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'filters' => ['status' => $status?->value],
            'hasInFlight' => $request->user()->hasGenerationsInFlight(),
        ]);
    }

    public function create(Request $request, PricingRepository $pricing): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => ImageSize::options(),
            'qualities' => ImageQuality::options(),
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'referenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'markupPercent' => config('billing.markup_percent'),
            'pricingError' => $this->pricingError($pricing),
            'recent' => GenerationResource::collection($request->user()->generations()->latest()->limit(4)->get())->resolve(),
            'hasInFlight' => $request->user()->hasGenerationsInFlight(),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $generation = $createGeneration->handle($request->user(), $request->toInput());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Generation queued. Up to {$generation->estimated_max_charge_nanos->format()} is reserved; you only pay the actual cost.",
        ]);

        return to_route('generations.create');
    }

    public function retry(Request $request, Generation $generation, CreateGeneration $createGeneration): RedirectResponse
    {
        Gate::authorize('retry', $generation);

        abort_unless($generation->status === GenerationStatus::Failed, 422, 'Only failed generations can be retried.');

        // A deterministic request id, so clicking "Retry" twice on the same failure creates one new generation.
        $createGeneration->handle($request->user(), GenerationInput::from(
            $generation,
            clientRequestId: Uuid::uuid5(Uuid::NAMESPACE_URL, "retry:{$generation->id}")->toString(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retrying with the same settings.']);

        return back();
    }

    /**
     * Warm the pricing cache and tell the page up front when generating is paused.
     */
    private function pricingError(PricingRepository $pricing): ?string
    {
        try {
            $pricing->current();

            return null;
        } catch (PricingUnavailableException $e) {
            report($e);

            return $e->getMessage();
        }
    }
}
