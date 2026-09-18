<?php

namespace App\Modules\PortalCliente\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalClienteHabilitado
{
    /**
     * Con el feature flag apagado, cualquier ruta del Portal Cliente debe
     * comportarse como si no existiera (404 genérico vía el mismo renderer
     * global de NotFoundHttpException que usa toda la API — ver
     * bootstrap/app.php), nunca un 401/403 que delate que la ruta existe
     * pero está bloqueada. Corre ANTES de permiso:portal.acceder a
     * propósito: si el flag está apagado, ni siquiera se evalúa el permiso.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('portal_cliente.enabled'), 404);

        return $next($request);
    }
}
