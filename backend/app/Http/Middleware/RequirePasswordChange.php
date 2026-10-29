<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('api.me', 'api.password.update', 'api.logout')) {
            if ($request->is('api/*') || $request->expectsJson()) {
                abort(403, 'Change your temporary password before continuing.');
            }

            return redirect()->route('password.required');
        }

        return $next($request);
    }
}
