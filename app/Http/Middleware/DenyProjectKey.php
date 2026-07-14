<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Whatever belongs to the account (profile, projects, keys, usage, admin) is off limits to a project API key. */
class DenyProjectKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof Project) {
            abort(403, 'Project API keys cannot access account endpoints.');
        }

        return $next($request);
    }
}
