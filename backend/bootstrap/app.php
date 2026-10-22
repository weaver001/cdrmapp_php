<?php

use App\Http\Middleware\ActiveAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['active' => ActiveAccount::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r, $e) => $r->is('api/*') || $r->expectsJson());
        $exceptions->respond(function ($response) {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $d = json_decode($response->getContent(), true) ?? [];

                return response()->json(['error' => ['message' => $d['message'] ?? 'Request failed.', 'fields' => $d['errors'] ?? (object) []]], $response->getStatusCode(), $response->headers->all());
            }

            return $response;
        });
    })->create();
