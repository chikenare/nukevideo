<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('nuke.webhook.secret');

        if ($signature = $request->header('x-e2-notification-signature')) {
            $payload = $request->getContent();
            $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

            if (! $secret || ! hash_equals($expected, $signature)) {
                return response()->json([
                    'message' => 'Invalid or missing webhook signature.',
                ], 403);
            }

            return $next($request);
        }

        $token = $request->bearerToken();

        if (! $secret || ! hash_equals($secret, (string) $token)) {
            return response()->json([
                'message' => 'Invalid or missing webhook signature.',
            ], 403);
        }

        return $next($request);
    }
}
