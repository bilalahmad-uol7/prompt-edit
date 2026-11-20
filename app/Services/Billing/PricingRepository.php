<?php

namespace App\Services\Billing;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Current Pixelforge pricing, cached with stale-while-revalidate.
 *
 * Rates are read from the API rather than hardcoded. If Pixelforge is unreachable
 * we fall back to the last pricing we successfully fetched. Estimates only: actual
 * charges always use the rates stored on each generation at creation.
 */
class PricingRepository
{
    private const string CACHE_KEY = 'pixelforge:pricing';

    private const string LAST_KNOWN_KEY = 'pixelforge:pricing:last-known';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly int $freshSeconds,
        private readonly int $staleSeconds,
    ) {}

    /**
     * @throws PixelforgeException when pricing was never fetched and Pixelforge is unreachable
     */
    public function current(): Pricing
    {
        try {
            $payload = Cache::flexible(self::CACHE_KEY, [$this->freshSeconds, $this->staleSeconds], fn () => $this->fetch());
        } catch (PixelforgeException $e) {
            $payload = Cache::get(self::LAST_KNOWN_KEY) ?? throw $e;
        }

        return Pricing::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $payload = $this->client->pricing()->toArray();

        Cache::forever(self::LAST_KNOWN_KEY, $payload);

        return $payload;
    }
}
