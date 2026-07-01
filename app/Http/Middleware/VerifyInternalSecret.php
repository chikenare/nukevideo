<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalSecret
{
    /** @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('nuke.internal.secret');
        $token = $request->bearerToken();

        if (! $secret || ! is_string($token) || ! hash_equals($secret, $token)) {
            return response()->json(['message' => 'Invalid or missing internal secret.'], 403);
        }

        return $next($request);
    }
}
