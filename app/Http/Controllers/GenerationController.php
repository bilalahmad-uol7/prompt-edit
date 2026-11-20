<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Data\GenerationInput;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Billing\PricingRepository;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Ramsey\Uuid\Uuid;

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
            'hasInFlight' => $request->user()->generations()->inFlight()->exists(),
        ]);
    }

    public function create(Request $request, PricingRepository $pricing): Response
    {
        try {
            $pricing->current();
            $pricingError = null;
        } catch (PixelforgeException $e) {
            report($e);
            $pricingError = 'Live pricing is temporarily unavailable, so new generations are paused. Please try again in a minute.';
        }

        return Inertia::render('generations/Create', [
            'sizes' => collect(ImageSize::cases())->map(fn (ImageSize $size) => ['value' => $size->value, 'label' => $size->label()]),
            'qualities' => collect(ImageQuality::cases())->map(fn (ImageQuality $quality) => ['value' => $quality->value, 'label' => $quality->label()]),
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'referenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'markupPercent' => config('billing.markup_percent'),
            'pricingError' => $pricingError,
            'recent' => GenerationResource::collection($request->user()->generations()->latest()->limit(4)->get())->resolve(),
            'hasInFlight' => $request->user()->generations()->inFlight()->exists(),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $generation = $createGeneration->handle($request->user(), $request->toInput());
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        } catch (PixelforgeException $e) {
            report($e);

            return back()->withErrors(['prompt' => 'We could not load current pricing from the image provider. Nothing was charged — please try again shortly.']);
        }

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

        try {
            // A deterministic request id, so clicking "Retry" twice on the same failure creates one new generation.
            $createGeneration->handle($request->user(), GenerationInput::from(
                $generation,
                clientRequestId: Uuid::uuid5(Uuid::NAMESPACE_URL, "retry:{$generation->id}")->toString(),
            ));
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        } catch (PixelforgeException $e) {
            report($e);

            return back()->withErrors(['credits' => 'We could not load current pricing. Nothing was charged — please try again shortly.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retrying with the same settings.']);

        return back();
    }
}
