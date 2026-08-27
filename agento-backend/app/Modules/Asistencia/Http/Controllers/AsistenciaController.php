<?php

namespace App\Modules\Asistencia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Application\ReprocesarAsistenciaRango;
use App\Modules\Asistencia\Http\Requests\ReprocesarAsistenciaRequest;
use App\Modules\Asistencia\Http\Requests\ResumenAsistenciaRequest;
use App\Modules\Asistencia\Http\Requests\StoreAsistenciaPermisoRequest;
use App\Modules\Asistencia\Http\Requests\StoreSolicitudAreaRequest;
use App\Modules\Asistencia\Http\Requests\StoreAsistenciaPeriodoRequest;
use App\Modules\Asistencia\Http\Requests\TransicionarAsistenciaRequest;
use App\Modules\Asistencia\Http\Requests\AsociarPersonIdRequest;
use App\Modules\Asistencia\Http\Requests\EditarAsistenciaDiaRequest;
use App\Modules\Asistencia\Http\Requests\ResolverAsistenciaMasivaRequest;
use App\Modules\Asistencia\Http\Requests\ImportarMarcacionesRequest;
use App\Modules\Asistencia\Http\Resources\AsistenciaResultadoResource;
use App\Modules\Asistencia\Http\Resources\AsistenciaColaboradorResource;
use App\Modules\Asistencia\Http\Resources\AsistenciaPermisoResource;
use App\Modules\Asistencia\Http\Resources\SolicitudAreaResource;
use App\Modules\Asistencia\Services\AsistenciaConsultaService;
use App\Modules\Asistencia\Services\AsistenciaPermisoService;
use App\Modules\Asistencia\Services\SolicitudAreaService;
use App\Modules\Asistencia\Services\ImportarMarcacionesService;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaSolicitudArea;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AsistenciaController extends Controller
{
    public function __construct(
        private readonly AsistenciaConsultaService $consultas,
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPermisoService $permisos,
        private readonly SolicitudAreaService $solicitudesArea,
        private readonly ImportarMarcacionesService $importador,
        private readonly AsistenciaDecisionService $decisiones,
        private readonly AsistenciaOperacionService $operaciones,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly ReprocesarAsistenciaRango $reprocesador,
    ) {}

    public function index(ResumenAsistenciaRequest $request): AnonymousResourceCollection
    {
        $empresa = $request->user('api')->empresa;
        $filtros = $request->validated();

        return AsistenciaResultadoResource::collection($this->consultas->listar($empresa, $filtros))
            ->additional(['stats' => $this->consultas->estadisticas($empresa, $filtros)]);
    }

    public function colaboradores(ResumenAsistenciaRequest $request): AnonymousResourceCollection
    {
        $empresa = $request->user('api')->empresa;
        $filtros = $request->validated();

        return AsistenciaColaboradorResource::collection($this->consultas->listarColaboradores($empresa, $filtros))
            ->additional(['stats' => $this->consultas->estadisticasColaboradores($empresa, $filtros)]);
    }

    public function colaborador(
        ResumenAsistenciaRequest $request,
        Colaborador $colaborador
    ): AsistenciaColaboradorResource {
        return new AsistenciaColaboradorResource(
            $this->consultas->obtenerColaborador(
                $request->user('api')->empresa,
                $colaborador,
                $request->validated(),
            )
        );
    }

    public function permisos(ResumenAsistenciaRequest $request): AnonymousResourceCollection
    {
        return AsistenciaPermisoResource::collection(
            $this->permisos->listar($request->user('api')->empresa, $request->validated())
        );
    }

    public function guardarPermiso(StoreAsistenciaPermisoRequest $request): AsistenciaPermisoResource
    {
        return new AsistenciaPermisoResource(
            $this->permisos->crear(
                $request->user('api')->empresa,
                $request->validated(),
                $request->user('api')->id,
            )
        );
    }

    public function resolverPermiso(TransicionarAsistenciaRequest $request, AsistenciaPermiso $permiso): JsonResponse
    {
        return response()->json(['data' => $this->decisiones->resolverPermiso(
            $request->user('api')->empresa, $permiso, $request->validated(), $request->user('api')
        )]);
    }

    public function gestionesArea(ResumenAsistenciaRequest $request): AnonymousResourceCollection
    {
        return SolicitudAreaResource::collection($this->solicitudesArea->listar(
            $request->user('api')->empresa, $request->user('api'),
            $request->input('estado'),
            min(100, max(1, (int) $request->input('per_page', 25))),
        ));
    }

    public function guardarGestionArea(StoreSolicitudAreaRequest $request): SolicitudAreaResource
    {
        return new SolicitudAreaResource($this->solicitudesArea->crear(
            $request->user('api')->empresa, $request->validated(), $request->user('api'),
        ));
    }

    public function importarMarcaciones(ImportarMarcacionesRequest $request): JsonResponse
    {
        $importacion = $this->importador->importar(
            $request->user('api')->empresa, $request->file('archivo'), $request->user('api')->id,
        );

        return response()->json([
            'message' => 'Marcaciones importadas y procesadas correctamente.',
            'data' => [
                'id' => $importacion->id, 'archivo_nombre' => $importacion->archivo_nombre,
                'filas_totales' => $importacion->filas_totales, 'filas_nuevas' => $importacion->filas_nuevas,
                'filas_duplicadas' => $importacion->filas_duplicadas, 'filas_observadas' => $importacion->filas_observadas,
                'fecha_minima' => $importacion->fecha_minima?->toDateString(), 'fecha_maxima' => $importacion->fecha_maxima?->toDateString(),
            ],
        ]);
    }

    public function previsualizarImportacion(ImportarMarcacionesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->importador->previsualizar(
            $request->user('api')->empresa, $request->file('archivo'),
        )]);
    }

    public function marcacionesNoAsociadas(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->importador->noAsociadas($request->user('api')->empresa)]);
    }

    public function asociarPersonId(AsociarPersonIdRequest $request, Colaborador $colaborador): JsonResponse
    {
        $cantidad = $this->importador->asociar(
            $request->user('api')->empresa, $colaborador, $request->validated('person_id')
        );
        return response()->json(['message' => 'Identidad biométrica asociada.', 'marcaciones_asociadas' => $cantidad]);
    }

    public function incidencias(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->incidencias($request->user('api')->empresa, $request->validated()));
    }

    public function resolverIncidencia(TransicionarAsistenciaRequest $request, AsistenciaIncidencia $incidencia): JsonResponse
    {
        return response()->json(['data' => $this->decisiones->resolverIncidencia(
            $request->user('api')->empresa, $incidencia, $request->validated(), $request->user('api')
        )]);
    }

    public function resolverIncidenciasMasivo(ResolverAsistenciaMasivaRequest $request): JsonResponse
    {
        $usuario = $request->user('api'); $datos = $request->validated();
        $incidencias = AsistenciaIncidencia::query()->where('empresa_id', $usuario->empresa->id)->whereIn('id', $datos['ids'])->get();
        abort_if($incidencias->count() !== count($datos['ids']), 404, 'Una o más incidencias no pertenecen a la empresa activa.');
        DB::transaction(fn () => $incidencias->each(fn ($incidencia) => $this->decisiones->resolverIncidencia($usuario->empresa, $incidencia, $datos, $usuario)));
        return response()->json(['message' => 'Incidencias resueltas.', 'procesadas' => $incidencias->count()]);
    }

    public function editarDia(EditarAsistenciaDiaRequest $request, AsistenciaResultadoDiario $resultado): JsonResponse
    {
        return response()->json(['data' => $this->decisiones->editarDia(
            $request->user('api')->empresa, $resultado, $request->validated(), $request->user('api')
        )]);
    }

    public function horasExtra(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->horasExtra($request->user('api')->empresa, $request->validated()));
    }

    public function resolverHoraExtra(TransicionarAsistenciaRequest $request, AsistenciaHoraExtra $horaExtra): JsonResponse
    {
        return response()->json(['data' => $this->decisiones->resolverHoraExtra(
            $request->user('api')->empresa, $horaExtra, $request->validated(), $request->user('api')
        )]);
    }

    public function resolverHorasExtraMasivo(ResolverAsistenciaMasivaRequest $request): JsonResponse
    {
        $usuario = $request->user('api'); $datos = $request->validated();
        $registros = AsistenciaHoraExtra::query()->where('empresa_id', $usuario->empresa->id)->whereIn('id', $datos['ids'])->get();
        abort_if($registros->count() !== count($datos['ids']), 404, 'Uno o más registros no pertenecen a la empresa activa.');
        DB::transaction(fn () => $registros->each(fn ($registro) => $this->decisiones->resolverHoraExtra($usuario->empresa, $registro, $datos, $usuario)));
        return response()->json(['message' => 'Horas extra resueltas.', 'procesadas' => $registros->count()]);
    }

    public function resolverGestionArea(TransicionarAsistenciaRequest $request, AsistenciaSolicitudArea $solicitud): JsonResponse
    {
        $usuario = $request->user('api');
        $esRrhh = $usuario->currentRole()?->clave === 'administrador' || $usuario->currentRole()?->permissions->contains('clave', 'asistencia.aprobar_rrhh');
        return response()->json(['data' => $this->decisiones->resolverSolicitud(
            $usuario->empresa, $solicitud, $request->validated(), $usuario, $esRrhh
        )]);
    }

    public function importaciones(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->importaciones($request->user('api')->empresa, min(100, (int) $request->input('per_page', 25))));
    }

    public function periodos(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json($this->periodos->listar($request->user('api')->empresa, min(100, (int) $request->input('per_page', 25))));
    }

    public function guardarPeriodo(StoreAsistenciaPeriodoRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->periodos->crear(
            $request->user('api')->empresa, $request->validated(), $request->user('api')->id
        )], 201);
    }

    public function transicionarPeriodo(TransicionarAsistenciaRequest $request, AsistenciaPeriodo $periodo): JsonResponse
    {
        return response()->json(['data' => $this->periodos->cambiarEstado(
            $request->user('api')->empresa, $periodo, $request->validated('accion'),
            $request->user('api')->id, $request->validated('motivo')
        )]);
    }

    public function auditoria(ResumenAsistenciaRequest $request): JsonResponse
    {
        return response()->json($this->operaciones->auditoria(
            $request->user('api')->empresa, min(100, (int) $request->input('per_page', 25)), $request->integer('entidad_id') ?: null
        ));
    }


    public function reprocesar(ReprocesarAsistenciaRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $resultado = $this->reprocesador->ejecutar(
            $request->user('api')->empresa,
            $request->user('api')->id,
            $datos['fecha_desde'],
            $datos['fecha_hasta'],
            $datos['colaborador_ids'] ?? null,
            $datos['motivo'] ?? null,
        );

        return response()->json([
            'message' => $resultado['omitidos_sin_rol_rotativo'] > 0
                ? "Asistencia reprocesada. {$resultado['omitidos_sin_rol_rotativo']} día(s) omitidos por horario rotativo sin rol declarado."
                : 'Asistencia reprocesada correctamente.',
            'resultados_procesados' => $resultado['procesados'],
            'omitidos_sin_rol_rotativo' => $resultado['omitidos_sin_rol_rotativo'],
        ]);
    }
}
