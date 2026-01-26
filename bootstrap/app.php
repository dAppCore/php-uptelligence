<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        // Core PHP Framework
        \Core\LifecycleEventProvider::class,
        \Core\Website\Boot::class,
        \Core\Front\Boot::class,
        \Core\Mod\Boot::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        \Core\Front\Boot::middleware($middleware);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
