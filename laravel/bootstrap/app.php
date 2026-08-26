<?php

use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Only trust proxies on the private RFC1918 ranges + loopback — Docker
        // bridge + cloudflared live here. Avoids trusting an arbitrary source.
        $middleware->trustProxies(at: [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'approved' => EnsureUserIsApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
