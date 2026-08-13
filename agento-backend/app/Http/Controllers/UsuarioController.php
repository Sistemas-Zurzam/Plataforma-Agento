<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateRolRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActiva = $request->user('api')->empresa;

        $usuarios = $this->usuarios->listar($empresaActiva)
            ->each(fn (User $usuario) => $usuario->setAttribute('empresaActiva', [
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
