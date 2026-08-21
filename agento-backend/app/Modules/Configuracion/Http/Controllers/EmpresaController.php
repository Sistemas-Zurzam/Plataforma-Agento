<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreEmpresaRequest;
use App\Modules\Configuracion\Http\Requests\UpdateEmpresaRequest;
use App\Modules\Configuracion\Http\Requests\UpdateEmpresaEstadoRequest;
use App\Modules\Configuracion\Http\Resources\EmpresaResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\EmpresaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmpresaController extends Controller
{
    public function __construct(private readonly EmpresaService $empresas) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $usuario = $request->user('api');

        // Un Administrador ve todas las empresas del sistema, no solo las
        // que tiene vinculadas explícitamente — ver User::esAdministradorGlobal().
        $empresas = $usuario->esAdministradorGlobal()
            ? Empresa::orderBy('nombre')->get()
            : $usuario->empresas()->orderBy('nombre')->get();

        return EmpresaResource::collection($empresas);
    }

    public function store(StoreEmpresaRequest $request): EmpresaResource
    {
        $empresa = $this->empresas->crearParaUsuario(
            $request->validated(),
            $request->user('api'),
        );

        return new EmpresaResource($empresa);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): EmpresaResource
    {
        $empresa = $this->empresas->actualizar(
            $empresa,
            $request->validated(),
            $request->user('api'),
        );

        return new EmpresaResource($empresa);
    }

    public function actualizarEstado(UpdateEmpresaEstadoRequest $request, Empresa $empresa): EmpresaResource
    {
        $empresa = $this->empresas->actualizarEstado(
            $empresa,
            $request->boolean('activa'),
            $request->user('api'),
        );

        return new EmpresaResource($empresa);
    }

    public function activar(Request $request, Empresa $empresa): JsonResponse
    {
        $this->empresas->activarParaUsuario($empresa, $request->user('api'));

        return response()->json([
            'message' => 'Empresa activa actualizada',
        ]);
    }

    public function guardarLogo(Request $request, Empresa $empresa): EmpresaResource
    {
        $datos = $request->validate([
            // 'image' no reconoce SVG (no es un raster) — se valida solo por
            // extensión/mime para permitir logos vectoriales también. El
            // tope es sobre el archivo ORIGINAL — EmpresaService::guardarLogo()
            // siempre redimensiona y recomprime antes de guardar en disco.
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:5120'],
        ]);

        $empresa = $this->empresas->guardarLogo($empresa, $datos['archivo'], $request->user('api'));

        return new EmpresaResource($empresa);
    }
}
