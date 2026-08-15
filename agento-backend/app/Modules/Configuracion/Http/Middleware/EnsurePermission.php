<?php

namespace App\Modules\Configuracion\Http\Middleware;

use App\Modules\Configuracion\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Restrict the route to users whose role in their active empresa has
     * the given permission clave. El rol administrador siempre pasa, sin
     * mirar la matriz de permisos — es el único rol con acceso total
     * garantizado (ver Role::ADMINISTRADOR).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        $rol = $request->user('api')?->currentRole();

        if ($rol?->clave === Role::ADMINISTRADOR) {
            return $next($request);
        }

        if (! $rol || ! $rol->permissions->contains('clave', $permiso)) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
