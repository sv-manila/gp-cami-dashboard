<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This callback REPLACES Laravel's default rule rather than adding to it,
        // and no route in this app lives under `api/*` — `api-try` has no slash —
        // so the previous version matched nothing and, worse, discarded the
        // built-in expectsJson() check. Every JSON endpoint then rendered its
        // errors as HTML: /api-try validation failures came back as a 302 redirect
        // page, so the docs page's `res.json()` threw instead of showing the
        // validation message, and /match + /stats/exact 404s were text/html.
        //
        // Keep expectsJson() and name the JSON routes explicitly, because the
        // fetch() calls in welcome.blade.php do not all send an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson()
                || $request->is('api-try', 'match/*', 'stats/exact/*'),
        );
    })->create();
