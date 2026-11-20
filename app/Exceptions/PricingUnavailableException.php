<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

/**
 * Current Pixelforge pricing could not be loaded, so no price can be quoted or held.
 * Reported (it is an operational problem) and rendered as a friendly message.
 */
class PricingUnavailableException extends RuntimeException
{
    public const string MESSAGE = 'Live pricing is temporarily unavailable, so new generations are paused. Nothing was charged — please try again in a minute.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, previous: $previous);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 503);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => self::MESSAGE]);

        return back();
    }
}
