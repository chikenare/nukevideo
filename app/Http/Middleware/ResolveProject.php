<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResolveProject
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $ulid = $request->header('X-Project-Ulid');

        if ($user && $ulid) {
            $project = $user->projects()->where('ulid', $ulid)->first();

            if (! $project) {
                return response()->json(['message' => 'Project not found'], 404);
            }

            $request->attributes->set('resolved_project', $project);
        }

        return $next($request);
    }
}
