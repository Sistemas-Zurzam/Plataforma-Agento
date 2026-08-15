<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\UpdatePermisosRequest;
use App\Modules\Configuracion\Http\Resources\PermissionResource;
use App\Modules\Configuracion\Http\Resources\RoleResource;
use App\Modules\Configuracion\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permisos) {}

    public function index(): JsonResponse
    {
        $datos = $this->permisos->listar();

        return response()->json([
            'roles' => RoleResource::collection($datos['roles']),
            'grupos' => $datos['grupos']->map(
                fn ($permisos) => PermissionResource::collection($permisos),
            ),
        ]);
    }

    public function update(UpdatePermisosRequest $request): JsonResponse
    {
        $this->permisos->sincronizar($request->validated('asignaciones'));

        $datos = $this->permisos->listar();

        return response()->json([
            'roles' => RoleResource::collection($datos['roles']),
            'grupos' => $datos['grupos']->map(
                fn ($permisos) => PermissionResource::collection($permisos),
            ),
        ]);
    }
}
