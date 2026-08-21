<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuracion\Http\Requests\StoreUsuarioRequest;
use App\Modules\Configuracion\Http\Requests\UpdateRolRequest;
use App\Modules\Configuracion\Http\Requests\UpdateUsuarioEstadoRequest;
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
        // whereIn() no garantiza el orden de $empresaIds — se reordena a mano
        // porque el primer id de la lista es la "empresa activa" inicial que
        // el frontend puso en primer lugar (para Administrador, la única).
        $empresaIds = $request->validated('empresa_ids');
        $porId = Empresa::whereIn('id', $empresaIds)->get()->keyBy('id');
        $empresas = collect($empresaIds)->map(fn ($id) => $porId->get($id))->filter()->values();

        $rol = Role::findOrFail($request->validated('role_id'));

        $usuario = $this->usuarios->crear(
            $empresas,
            $request->only('name', 'username', 'email', 'password', 'area_id'),
            $rol,
            $request->user('api'),
        );

        $empresaPrincipal = $empresas->first();
        $usuario->setAttribute('empresaActiva', [
            'id' => $empresaPrincipal->id,
            'nombre' => $empresaPrincipal->nombre,
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

    public function actualizarEstado(
        UpdateUsuarioEstadoRequest $request,
        User $usuario,
    ): UsuarioResource {
        $empresaActiva = $request->user('api')->empresa;
        $usuario = $this->usuarios->cambiarEstado(
            $empresaActiva,
            $usuario,
            $request->boolean('activo'),
            $request->user('api'),
        );

        $usuarioActualizado = $empresaActiva->users()->with('area')->where('users.id', $usuario->id)->first();
        $usuarioActualizado->setAttribute('empresaActiva', [
            'id' => $empresaActiva->id,
            'nombre' => $empresaActiva->nombre,
        ]);

        return new UsuarioResource($usuarioActualizado);
    }
}
