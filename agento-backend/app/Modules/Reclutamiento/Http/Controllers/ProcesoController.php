<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Reclutamiento\Application\ProcesoService;
use App\Modules\Reclutamiento\Http\Requests\ActualizarProcesoRequest;
use App\Modules\Reclutamiento\Http\Requests\EstadoProcesoRequest;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Requests\PerfilProcesoRequest;
use App\Modules\Reclutamiento\Http\Requests\PublicarProcesoRequest;
use App\Modules\Reclutamiento\Http\Resources\ActividadResource;
use App\Modules\Reclutamiento\Http\Resources\ProcesoResource;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProcesoController extends SeleccionController
{
    public function __construct(private readonly ProcesoService $procesos) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProcesoResource::collection(
            $this->procesos->listar($this->alcance($request), $request->only(['estado', 'area_id', 'reclutador_id', 'busqueda']), $this->porPagina($request)),
        );
    }

    public function show(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $this->procesos->autorizarLectura($this->alcance($request), $proceso);

        return (new ProcesoResource($this->procesos->detalle($proceso)))
            ->additional(['candidatos_por_etapa' => $this->procesos->candidatosPorEtapa($proceso)])
            ->response();
    }

    public function update(ActualizarProcesoRequest $request, ProcesoSeleccion $proceso): ProcesoResource
    {
        return new ProcesoResource($this->procesos->actualizar($this->alcance($request), $proceso, $request->validated()));
    }

    public function guardarPerfil(PerfilProcesoRequest $request, ProcesoSeleccion $proceso): ProcesoResource
    {
        return new ProcesoResource($this->procesos->guardarPerfil($this->alcance($request), $proceso, $request->validated()));
    }

    public function publicar(PublicarProcesoRequest $request, ProcesoSeleccion $proceso): ProcesoResource
    {
        return new ProcesoResource($this->procesos->publicar(
            $this->alcance($request), $proceso, $request->boolean('interna'), $request->boolean('externa'),
        ));
    }

    public function retirarPublicacion(MotivoRequest $request, ProcesoSeleccion $proceso): ProcesoResource
    {
        $motivo = $request->validated('motivo');
        if (blank($motivo)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo para retirar la publicación.']);
        }

        return new ProcesoResource($this->procesos->retirarPublicacion($this->alcance($request), $proceso, $motivo));
    }

    public function cambiarEstado(EstadoProcesoRequest $request, ProcesoSeleccion $proceso): ProcesoResource
    {
        $datos = $request->validated();

        return new ProcesoResource($this->procesos->cambiarEstado(
            $this->alcance($request), $proceso, $datos['accion'], $datos['motivo'] ?? null, $datos['mensaje_publico'] ?? null,
        ));
    }

    public function actividad(Request $request, ProcesoSeleccion $proceso): AnonymousResourceCollection
    {
        $this->procesos->autorizarLectura($this->alcance($request), $proceso);

        return ActividadResource::collection($this->procesos->actividadDe($proceso));
    }

    /**
     * Usuarios de la empresa activa para elegir reclutador o evaluador.
     */
    public function usuarios(Request $request): JsonResponse
    {
        $empresa = Empresa::findOrFail($this->alcance($request)->empresaId);
        $usuarios = $empresa->users()->where('activo', true)->orderBy('name')->get(['users.id', 'users.name']);

        return response()->json(['data' => $usuarios->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->name])]);
    }
}
