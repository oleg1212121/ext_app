<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_approved && ! $request->routeIs('pending-approval')) {
            return redirect()->route('pending-approval');
        }

        return $next($request);
    }
}
