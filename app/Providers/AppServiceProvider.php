<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Billing\CostCalculator;
use App\Services\Billing\PricingRepository;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            baseUrl: config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeout: config('pixelforge.timeout'),
            connectTimeout: config('pixelforge.connect_timeout'),
        ));

        $this->app->singleton(CostCalculator::class, fn (): CostCalculator => new CostCalculator(
            markupPercent: config('billing.markup_percent'),
        ));

        $this->app->singleton(PricingRepository::class, fn ($app): PricingRepository => new PricingRepository(
            client: $app->make(PixelforgeClient::class),
            freshSeconds: config('billing.pricing_cache.fresh'),
            staleSeconds: config('billing.pricing_cache.stale'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        Gate::define('view-admin-report', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Pixelforge allows 60 requests per minute per account. Submission jobs are throttled
     * below that so we rarely hit the limit; when we do, the job waits for Retry-After.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('pixelforge', fn (): Limit => Limit::perMinute(config('pixelforge.submission.requests_per_minute')));

        RateLimiter::for('generations', fn ($request): Limit => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
