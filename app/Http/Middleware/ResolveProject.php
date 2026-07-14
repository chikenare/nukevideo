<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the project a request works on. Two kinds of caller reach here:
 * a user (session or personal token) naming the project with a header, and a project API key,
 * which authenticates as the project itself and therefore needs no header.
 */
class ResolveProject
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user();
        $ulid = $request->header('X-Project-Ulid');

        if ($actor instanceof Project) {
            if ($ulid && $ulid !== $actor->ulid) {
                return response()->json(['message' => 'This API key belongs to another project.'], 403);
            }

            $request->attributes->set('resolved_project', $actor);

            return $next($request);
        }

        if ($actor && $ulid) {
            $project = $actor->projects()->where('ulid', $ulid)->first();

            if (! $project) {
                return response()->json(['message' => 'Project not found'], 404);
            }

            $request->attributes->set('resolved_project', $project);
        }

        return $next($request);
    }
}
