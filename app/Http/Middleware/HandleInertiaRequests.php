<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'credits' => fn (): ?array => $this->credits($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The signed-in user's credit, shown in the sidebar and used by the generate form.
     *
     * @return array{balance: string, held: string, available: string}|null
     */
    private function credits(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $held = $user->heldBalance();

        return [
            'balance' => $user->balance_nanos->toDecimal(),
            'held' => $held->toDecimal(),
            'available' => $user->balance_nanos->minus($held)->toDecimal(),
        ];
    }
}
