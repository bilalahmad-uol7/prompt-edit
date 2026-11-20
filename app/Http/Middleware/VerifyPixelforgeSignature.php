<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix timestamp>,v1=<hex hmac>".
 *
 * The signature is an HMAC-SHA256 of "<timestamp>.<raw body>" keyed with the webhook
 * secret. It is checked against the raw body (before any JSON decoding), compared in
 * constant time, and stale timestamps are rejected to prevent replays.
 */
class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('pixelforge.webhook.secret');

        if ($secret === '') {
            Log::critical('Pixelforge webhook secret is not configured.');

            abort(500, 'Webhook secret not configured.');
        }

        [$timestamp, $signatures] = $this->parseHeader((string) $request->header('Pixelforge-Signature'));

        if ($timestamp === null || $signatures === []) {
            abort(400, 'Missing or malformed signature.');
        }

        if (abs(now()->getTimestamp() - $timestamp) > config('pixelforge.webhook.tolerance')) {
            abort(400, 'Signature timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        abort(400, 'Invalid signature.');
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
