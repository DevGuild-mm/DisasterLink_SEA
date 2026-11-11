<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits `POST /register` (the `register` limiter, set in FortifyServiceProvider). Fortify has no registration
 * limiter, and every registration sends a verification email, so unlimited requests could be used to send mail.
 * Fortify's routes cannot take extra middleware one by one, so this web middleware applies only to that route.
 */
class ThrottleRegistrations
{
    public function __construct(private ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('register.store')) {
            return $this->throttle->handle($request, $next, 'register');
        }

        return $next($request);
    }
}
