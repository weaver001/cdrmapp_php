<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveAccount
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->active, 403, 'Account is inactive.');

        return $next($r);
    }
}
