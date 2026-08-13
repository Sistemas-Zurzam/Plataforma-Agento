<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsEmpresaAdmin
{
    /**
     * Restrict the route to users whose role in their active empresa is
     * "administrador". This is a simple role gate, not a granular
     * permissions system — suficiente para "Usuarios y Roles" por ahora;
     * un sistema de permisos por módulo/acción se construirá aparte.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rol = $request->user('api')?->currentRole();

        if ($rol?->clave !== Role::ADMINISTRADOR) {
            return response()->json([
                'message' => 'No tienes permisos de administrador en esta empresa.',
            ], 403);
        }

        return $next($request);
    }
}
