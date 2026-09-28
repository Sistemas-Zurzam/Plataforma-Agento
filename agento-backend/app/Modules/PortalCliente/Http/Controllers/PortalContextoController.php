<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalContextoController extends Controller
{
    /**
     * Resuelve todo desde el usuario autenticado y su empresa activa
     * (users.empresa_id, vía User::currentRole()) — nunca desde un
     * empresa_id enviado por query, body o parámetros de ruta.
     */
    public function contexto(Request $request): JsonResponse
    {
        $usuario = $request->user('api');
        $empresa = $usuario->empresa;
        $rol = $usuario->currentRole();

        $permisosPortal = $rol
            ? $rol->permissions->pluck('clave')
                ->filter(fn (string $clave) => str_starts_with($clave, 'portal.'))
                ->values()
            : collect();

        return response()->json([
            'data' => [
                'empresa' => [
                    'id' => $empresa->id,
                    'razon_social' => $empresa->razon_social,
                    'nombre_comercial' => $empresa->nombre_comercial,
                ],
                'rol_actual' => $rol?->clave,
                'es_portal_cliente' => $rol?->clave === Role::CLIENTE_EMPRESA,
                'permisos' => $permisosPortal,
            ],
        ]);
    }
}
