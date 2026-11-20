<?php

namespace App\Exceptions;

use App\Support\Money\Usd;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use RuntimeException;

/**
 * The user cannot afford the maximum possible cost of a generation.
 * An expected outcome, so it is not reported; it is shown to the user as a toast.
 */
class InsufficientCreditsException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly Usd $available, public readonly Usd $required)
    {
        parent::__construct(sprintf(
            'This generation can cost up to %s but you have %s available. Buy credits to continue.',
            $required->format(),
            $available->format(),
        ));
    }

    public function render(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back();
    }
}
