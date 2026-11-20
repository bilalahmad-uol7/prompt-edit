<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Data\GenerationData;
use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\UsageDay;
use App\Services\Pixelforge\Exceptions\PixelforgeConnectionException;
use App\Services\Pixelforge\Exceptions\PixelforgeRequestException;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Pixelforge API. It never retries on its own:
 * callers decide (queued jobs retry with backoff, web requests fail fast).
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
    ) {}

    public function pricing(): Pricing
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/pricing'), authenticated: false);

        return Pricing::fromArray($response->json());
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $parameters
     */
    public function createGeneration(array $parameters, string $idempotencyKey): GenerationData
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('/generations', $parameters),
        );

        return GenerationData::fromArray($response->json());
    }

    public function getGeneration(string $id): GenerationData
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($id)));

        return GenerationData::fromArray($response->json());
    }

    /**
     * What Pixelforge billed this API key per UTC day (by completion date). Max 92 days per call.
     *
     * @return array<string, UsageDay> keyed by Y-m-d
     */
    public function usage(CarbonInterface $from, CarbonInterface $to): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]));

        $days = [];

        foreach ((array) $response->json('data', []) as $row) {
            $day = UsageDay::fromArray((array) $row);
            $days[$day->date] = $day;
        }

        return $days;
    }

    /**
     * @param  Closure(PendingRequest): Response  $callback
     *
     * @throws PixelforgeRequestException
     * @throws PixelforgeConnectionException
     */
    private function send(Closure $callback, bool $authenticated = true): Response
    {
        $http = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($authenticated) {
            $http->withToken((string) $this->apiKey);
        }

        try {
            $response = $callback($http);
        } catch (ConnectionException $e) {
            throw new PixelforgeConnectionException($e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw PixelforgeRequestException::fromResponse($response);
        }

        return $response;
    }
}
