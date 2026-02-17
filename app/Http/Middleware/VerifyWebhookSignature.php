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
        $token = $request->bearerToken();

        if (!$secret || !hash_equals($secret, (string) $token)) {
            return response()->json([
                'message' => 'Invalid or missing webhook signature.'
            ], 403);
        }


        return $next($request);
    }
}
