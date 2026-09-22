<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra toda la API del docente a las cuentas de monitor: un monitor solo usa
 * /api/monitor/* (más cerrar sesión y ver su propio usuario). Así ningún
 * endpoint nuevo queda expuesto a monitores por olvido de una policy.
 */
class EnsureNotMonitor
{
    private const ALLOWED = ['api/auth/logout', 'api/auth/me'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isMonitor() && ! ($request->is(...self::ALLOWED) && in_array($request->method(), ['GET', 'POST'], true))) {
            abort(403, 'Tu usuario de monitor no tiene acceso a esta sección.');
        }

        return $next($request);
    }
}
