<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyBillingWebhookSignature
{
    /**
     * Verify the I-BLS-2 webhook signature (HMAC-SHA256) and reject replays.
     *
     * Header format: X-Billings-Signature: t=TIMESTAMP,v1=SIGNATURE
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.billingsystems.webhook_secret');

        // If no secret is configured, skip verification (dev/testing only)
        if (empty($secret)) {
            Log::warning('Billing webhook: no webhook_secret configured — skipping signature verification');
            return $next($request);
        }

        $signatureHeader = $request->header('X-Billings-Signature');

        if (empty($signatureHeader)) {
            Log::warning('Billing webhook: missing X-Billings-Signature header', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Missing signature'], 403);
        }

        // Parse t=TIMESTAMP,v1=SIGNATURE
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signature = substr($part, 3);
            }
        }

        if (empty($timestamp) || empty($signature)) {
            Log::warning('Billing webhook: malformed signature header', [
                'header' => $signatureHeader,
                'ip'     => $request->ip(),
            ]);
            return response()->json(['error' => 'Malformed signature'], 403);
        }

        // Replay protection: reject if timestamp is older than 300 seconds
        $age = time() - (int) $timestamp;
        if ($age > 300) {
            Log::warning('Billing webhook: signature timestamp expired', [
                'timestamp' => $timestamp,
                'age'       => $age,
                'ip'        => $request->ip(),
            ]);
            return response()->json(['error' => 'Signature expired'], 403);
        }

        // Compute expected signature
        $rawBody  = $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Billing webhook: signature mismatch', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        return $next($request);
    }
}
