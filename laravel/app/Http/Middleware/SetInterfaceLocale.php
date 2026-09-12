<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetInterfaceLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The admin panel stays English regardless of the admin's preference.
        if ($request->is('admin') || $request->is('admin/*')) {
            return $next($request);
        }

        app()->setLocale($request->user()?->resolvedInterfaceLocale() ?? config('app.locale'));

        return $next($request);
    }
}
