<?php

namespace App\Providers;

use App\Services\Billing\CostCalculator;
use App\Services\Billing\PricingRepository;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
