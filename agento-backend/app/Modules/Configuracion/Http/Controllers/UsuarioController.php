<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuracion\Http\Requests\StoreUsuarioRequest;
use App\Modules\Configuracion\Http\Requests\UpdateRolRequest;
use App\Modules\Configuracion\Http\Requests\UpdateUsuarioRequest;
use App\Modules\Configuracion\Http\Resources\UsuarioResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActiva = $request->user('api')->empresa;
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $usuarios = $this->usuarios->listar($empresaActiva, $perPage);

        $usuarios->getCollection()->each(fn (User $usuario) => $usuario->setAttribute('empresaActiva', [
            'id' => $empresaActiva->id,
            'nombre' => $empresaActiva->nombre,
        ]));

        return UsuarioResource::collection($usuarios);
    }

    public function store(StoreUsuarioRequest $request): JsonResponse
    {
        $empresaDestino = Empresa::findOrFail($request->validated('empresa_id'));
        $rol = Role::findOrFail($request->validated('role_id'));

        $usuario = $this->usuarios->crear(
            $empresaDestino,
            $request->only('name', 'username', 'email', 'password', 'area_id'),
            $rol,
        );

        $usuario->setAttribute('empresaActiva', [
            'id' => $empresaDestino->id,
            'nombre' => $empresaDestino->nombre,
        ]);

        return (new UsuarioResource($usuario))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario): UsuarioResource
    {
        $empresaActiva = $request->user('api')->empresa;

        $this->usuarios->actualizar(
            $empresaActiva,
            $usuario,
            $request->only('name', 'username', 'email', 'area_id', 'password'),
        );

        $usuarioActualizado = $empresaActiva->users()->with('area')->where('users.id', $usuario->id)->first();
        $usuarioActualizado->setAttribute('empresaActiva', [
            'id' => $empresaActiva->id,
            'nombre' => $empresaActiva->nombre,
        ]);

        return new UsuarioResource($usuarioActualizado);
    }

    public function actualizarRol(UpdateRolRequest $request, User $usuario): UsuarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $rol = Role::findOrFail($request->validated('role_id'));

        $this->usuarios->cambiarRol($empresaActiva, $usuario, $rol, $request->user('api'));

        $usuarioActualizado = $empresaActiva->users()->where('users.id', $usuario->id)->first();
        $usuarioActualizado->setAttribute('empresaActiva', [
            'id' => $empresaActiva->id,
            'nombre' => $empresaActiva->nombre,
        ]);

        return new UsuarioResource($usuarioActualizado);
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;

        $this->usuarios->eliminar($empresaActiva, $usuario, $request->user('api'));

        return response()->json(['message' => 'Usuario eliminado de la empresa']);
    }
}
