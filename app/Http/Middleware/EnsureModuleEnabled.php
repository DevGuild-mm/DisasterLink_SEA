<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    /**
     * Abort with 404 when the named post-MVP module is switched off in config/modules.php.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! config("modules.$module")) {
            abort(404);
        }

        return $next($request);
    }
}
