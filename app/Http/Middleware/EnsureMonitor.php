<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo cuentas de monitor de curso. */
class EnsureMonitor
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isMonitor(), 403, 'Esta sección es solo para monitores de curso.');

        return $next($request);
    }
}
