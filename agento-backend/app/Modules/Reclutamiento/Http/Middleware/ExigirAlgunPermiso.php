<?php

namespace App\Modules\Reclutamiento\Http\Middleware;

use App\Modules\Configuracion\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Variante de EnsurePermission (mismo RBAC: rol en la empresa activa +
 * permisos) que acepta CUALQUIERA de varios permisos. Uso:
 *   ->middleware(ExigirAlgunPermiso::class.':seleccion.seguimiento,seleccion.vacantes.gestionar')
 */
class ExigirAlgunPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $rol = $request->user('api')?->currentRole();

        if ($rol?->clave === Role::ADMINISTRADOR) {
            return $next($request);
        }

        if (! $rol || ! $rol->permissions->pluck('clave')->intersect($permisos)->isNotEmpty()) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
