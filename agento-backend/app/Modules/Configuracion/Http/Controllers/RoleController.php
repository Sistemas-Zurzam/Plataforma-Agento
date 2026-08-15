<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreRoleRequest;
use App\Modules\Configuracion\Http\Requests\UpdateRoleRequest;
use App\Modules\Configuracion\Http\Resources\RoleResource;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActivaId = $request->user('api')->empresa_id;

        $roles = Role::withCount(['empresaUsuarios as usuarios_count' => function ($query) use ($empresaActivaId) {
            $query->where('empresa_id', $empresaActivaId);
        }])->orderBy('id')->get();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $rol = $this->roles->crear($request->validated());

        return (new RoleResource($rol))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $rol = $this->roles->actualizar($role, $request->validated());

        return new RoleResource($rol);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->eliminar($role);

        return response()->json(['message' => 'Rol eliminado']);
    }
}
