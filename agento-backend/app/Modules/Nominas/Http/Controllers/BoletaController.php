<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\ReporteEjecutivo\ConceptosEfectivosPorBoleta;
use App\Modules\Nominas\Application\ReporteEjecutivo\ReporteEjecutivoRemuneracionesCalculador;
use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Http\Resources\IncidenciaPendienteResource;
use App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export\ReporteEjecutivoRemuneracionesExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\AportesPrevisionalesService;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Support\ProrateoRango;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoletaController extends Controller
{
    public function __construct(
        private readonly BoletaService $boletas,
        private readonly AportesPrevisionalesService $aportesPrevisionales,
    ) {}

    /**
     * Igual que CicloRemunerativoController::empresaAutorizadaDelCiclo(): el
     * usuario puede operar sobre boletas/ciclos de cualquier empresa que
     * realmente administre, no solo la empresa activa de la sesión.
     */
    private function empresaAutorizadaDelCiclo(Request $request, CicloRemunerativo $ciclo): Empresa
    {
        $empresa = $ciclo->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a la empresa de este ciclo remunerativo.');

        return $empresa;
    }

    private function empresaAutorizadaDeLaBoleta(Request $request, Boleta $boleta): Empresa
    {
        $empresa = $boleta->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a la empresa de esta boleta.');

        return $empresa;
    }

    /**
     * Previsualización mensual continua — no requiere un ciclo creado
     * (Sección 5/32 de la documentación funcional). Documento de solo
     * lectura, nunca oficial: no se persiste nada.
     */
    public function previsualizar(Request $request): JsonResponse
    {
        ['filas' => $filas, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta] = $this->resolverPrevisualizacion($request);

        $porEmpresa = collect($filas)->groupBy('empresa_id')->map(function ($grupo) {
            $calculables = $grupo->where('estado', 'calculable');

            return [
                'empresa_id' => $grupo->first()['empresa_id'],
                'empresa' => $grupo->first()['empresa'],
                'colaboradores' => $grupo->count(),
                'no_calculables' => $grupo->count() - $calculables->count(),
                'total_ingresos' => round((float) $calculables->sum('total_ingresos'), 2),
                'total_egresos' => round((float) $calculables->sum('total_egresos'), 2),
                'total_aportaciones' => round((float) $calculables->sum('total_aportaciones'), 2),
                'neto_a_pagar' => round((float) $calculables->sum('neto_a_pagar'), 2),
            ];
        })->values();

        return response()->json(['data' => [
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'dias' => ProrateoRango::diasDelRango($desde, $hasta),
            'empresas' => $porEmpresa,
            'totales' => [
                'empresas' => $porEmpresa->count(),
                'colaboradores' => $porEmpresa->sum('colaboradores'),
                'total_ingresos' => round($porEmpresa->sum('total_ingresos'), 2),
                'total_egresos' => round($porEmpresa->sum('total_egresos'), 2),
                'total_aportaciones' => round($porEmpresa->sum('total_aportaciones'), 2),
                'neto_a_pagar' => round($porEmpresa->sum('neto_a_pagar'), 2),
            ],
            'filas' => array_map(fn (array $fila) => array_diff_key($fila, ['lineas' => true]), $filas),
        ]]);
    }

    /**
     * Proyectado de planilla en Excel, con el mismo formato que el Excel
     * del Resumen contable (una columna por concepto, bonos/HE/descuentos
     * separados, subtotales y hoja de resumen ejecutivo).
     */
    public function previsualizarExcel(Request $request): Response
    {
        $reporte = $this->armarReporteProyectado($request);

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generarDesdeFilas(
            $reporte['periodo'], $reporte['filas'], $reporte['conceptos'], 'PROYECTADO DE PLANILLA', $reporte['no_calculables'],
        );
        $sufijo = $reporte['empresas']->count() === 1 ? Str::slug($reporte['empresas']->first()->nombre_comercial) : 'todas';
        $nombre = sprintf('Proyectado_planilla_%s_%s_%s.xlsx', $sufijo, $reporte['fecha_desde'], $reporte['fecha_hasta']);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }

    /**
     * Mismos datos que previsualizarExcel() en JSON, con la misma forma que
     * CicloRemunerativoController::datosReporteEjecutivo(), para la vista
     * imprimible en PDF (ReporteEjecutivoImprimibleModal).
     */
    public function previsualizarDatosReporte(Request $request): JsonResponse
    {
        $reporte = $this->armarReporteProyectado($request);

        ['columnas' => $columnas, 'filas' => $filas] = ReporteEjecutivoRemuneracionesCalculador::detalle($reporte['filas'], $reporte['conceptos']);
        $claves = array_column($columnas, 'clave');

        return response()->json([
            'titulo' => 'PROYECTADO DE PLANILLA',
            'periodo' => $reporte['periodo'],
            'columnas' => $columnas,
            'empresas' => $filas->groupBy('empresa')
                ->map(fn ($grupo, $empresa) => [
                    'empresa' => $empresa,
                    'colaboradores' => $grupo->count(),
                    'filas' => $grupo->values(),
                    'subtotal' => ReporteEjecutivoRemuneracionesCalculador::sumarMontos($grupo, $claves),
                ])
                ->values(),
            'total_general' => ReporteEjecutivoRemuneracionesCalculador::sumarMontos($filas, $claves),
            'total_colaboradores' => $filas->count(),
            'no_calculables' => $reporte['no_calculables'],
        ]);
    }

    /**
     * Filas del Proyectado con la forma de ReporteEjecutivoRemuneracionesCalculador::filas()
     * — compartido entre el Excel y el PDF para que nunca diverjan.
     *
     * @return array{periodo: string, filas: Collection<int, array<string, mixed>>, conceptos: Collection<int, Collection<int, array<string, mixed>>>,
     *   no_calculables: list<array{nombre: string, cargo: ?string, motivo: string}>, fecha_desde: string, fecha_hasta: string, empresas: Collection<int, Empresa>}
     */
    private function armarReporteProyectado(Request $request): array
    {
        ['filas' => $filas, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta, 'hoy' => $hoy, 'empresas' => $empresas] = $this->resolverPrevisualizacion($request);

        $periodo = Carbon::parse($desde)->format('d/m/Y').' al '.Carbon::parse($hasta)->format('d/m/Y')
            .' (proyectado al '.Carbon::parse($hoy)->format('d/m/Y').')';

        $calculables = collect($filas)->where('estado', 'calculable');
        $filasReporte = $calculables->map(fn (array $fila) => [
            // detalle() indexa los conceptos por `boleta_id`; aquí no hay
            // boleta, se usa el colaborador (único entre empresas).
            'boleta_id' => $fila['colaborador_id'],
            'empresa' => $fila['empresa'],
            'periodo' => $periodo,
            'dni' => $fila['dni'],
            'nombre' => $fila['nombre'],
            'area' => $fila['area'],
            'cargo' => (string) ($fila['cargo'] ?? ''),
            'tipo' => $fila['tipo'],
            'estado' => $fila['fecha_corte_asistencia']
                ? 'Asistencia al '.Carbon::parse($fila['fecha_corte_asistencia'])->format('d/m')
                : 'Sin asistencia procesada',
        ])->values();

        return [
            'periodo' => $periodo,
            'filas' => $filasReporte,
            'conceptos' => ConceptosEfectivosPorBoleta::desdeResultados(
                $calculables->mapWithKeys(fn (array $fila) => [$fila['colaborador_id'] => $fila['lineas']])
            ),
            'no_calculables' => collect($filas)->where('estado', 'no_calculable')
                ->map(fn (array $fila) => ['nombre' => "{$fila['nombre']} ({$fila['empresa']})", 'cargo' => $fila['cargo'], 'motivo' => (string) $fila['motivo']])
                ->values()->all(),
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'empresas' => $empresas,
        ];
    }

    /**
     * Proyectado de planilla por rango de fechas (máx. 31 días) y por
     * empresa: una empresa autorizada o, sin empresa_id, todas las activas
     * que el usuario administra. Se paga sueldo/30 × días del rango (un mes
     * calendario completo = 30, ver ProrateoRango); la asistencia se lee
     * hasta el último día procesado de cada colaborador y el resto se
     * proyecta al sueldo (ver BoletaService::previsualizarPlanilla()).
     *
     * @return array{filas: array<int, array<string, mixed>>, fecha_desde: string, fecha_hasta: string, hoy: string, empresas: Collection<int, Empresa>}
     */
    private function resolverPrevisualizacion(Request $request): array
    {
        $datos = $request->validate([
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            'fecha_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'empresa_id' => ['nullable', 'integer'],
            // Opcional: hasta este día asistencia real, después proyección.
            'fecha_corte_asistencia' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde', 'before_or_equal:fecha_hasta'],
        ]);
        if (Carbon::parse($datos['fecha_desde'])->diffInDays(Carbon::parse($datos['fecha_hasta'])) > 30) {
            throw ValidationException::withMessages(['fecha_hasta' => 'El rango no puede superar 31 días.']);
        }

        $usuario = $request->user('api');
        $empresaIds = $usuario->empresaIdsAutorizadas();
        if (! empty($datos['empresa_id'])) {
            // empresa_id del request nunca autoriza por sí solo: solo acota
            // dentro de las empresas que el usuario ya administra.
            abort_unless(in_array((int) $datos['empresa_id'], $empresaIds, true), 403, 'No tienes acceso a esa empresa.');
            $empresaIds = [(int) $datos['empresa_id']];
        }
        $empresas = Empresa::whereIn('id', $empresaIds)
            ->when(empty($datos['empresa_id']), fn ($query) => $query->where('activa', true))
            ->orderBy('nombre_comercial')
            ->get();

        [$desde, $hasta] = [$datos['fecha_desde'], $datos['fecha_hasta']];
        $corteAsistencia = $datos['fecha_corte_asistencia'] ?? null;
        $hoy = app(FechaOperativa::class)->hoy();

        $filas = [];
        foreach ($empresas as $empresa) {
            $filasEmpresa = $this->enContextoDeEmpresa($usuario, $empresa, function () use ($empresa, $desde, $hasta, $hoy, $corteAsistencia) {
                // Bonos/descuentos registrados en el ciclo solo si el rango
                // cubre el ciclo completo (no se proyecta un bono mensual
                // entero en una quincena).
                $ciclo = CicloRemunerativo::where('empresa_id', $empresa->id)
                    ->whereDate('fecha_inicio', '>=', $desde)->whereDate('fecha_fin', '<=', $hasta)
                    ->orderBy('fecha_inicio')->first();

                return $this->boletas->previsualizarPlanilla($empresa, $desde, $hasta, $hasta, $hoy->copy(), $ciclo?->id, prorratearRango: true, corteAsistencia: $corteAsistencia);
            });

            foreach ($filasEmpresa as $fila) {
                $filas[] = ['empresa_id' => $empresa->id, 'empresa' => $empresa->nombre_comercial, ...$fila];
            }
        }

        return ['filas' => $filas, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta, 'hoy' => $hoy->toDateString(), 'empresas' => $empresas];
    }

    /**
     * El motor de planilla consulta modelos con EmpresaScope (asistencia,
     * horarios, parámetros laborales…), que filtran por la empresa ACTIVA
     * del usuario. La planilla oficial de otra empresa corre en cola (sin
     * usuario, sin scope); aquí, en una petición HTTP, se fija en memoria
     * —nunca se guarda— la empresa ya autorizada como contexto, para que
     * cada consulta quede acotada a ESA empresa, y se restaura al terminar.
     */
    private function enContextoDeEmpresa(User $usuario, Empresa $empresa, callable $calculo): mixed
    {
        $empresaOriginal = $usuario->getAttribute('empresa_id');
        $usuario->setAttribute('empresa_id', $empresa->id);

        try {
            return $calculo();
        } finally {
            $usuario->setAttribute('empresa_id', $empresaOriginal);
        }
    }

    public function index(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $tipo = $request->input('tipo');

        return BoletaResource::collection(
            $this->boletas->listar($empresa, $ciclo, max(1, min((int) $request->input('per_page', 25), 100)), $tipo, $request->input('busqueda')),
        );
    }

    public function idsExportables(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json([
            'data' => $this->boletas->idsExportables(
                $empresa,
                $ciclo,
                $request->input('tipo'),
                $request->input('busqueda'),
            ),
        ]);
    }

    public function show(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource($this->boletas->ver($empresa, $boleta));
    }

    public function imprimirMasivo(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $datos = $request->validate(['boleta_ids' => ['required', 'array', 'min:1'], 'boleta_ids.*' => ['required', 'integer', 'distinct']]);
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return BoletaResource::collection($this->boletas->verMasivo($empresa, $ciclo, $datos['boleta_ids']));
    }

    public function resumen(Request $request, CicloRemunerativo $ciclo)
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json($this->boletas->resumen($empresa, $ciclo, $request->input('tipo'), $request->input('busqueda')));
    }

    public function registrarHorasExtraManual(Request $request, CicloRemunerativo $ciclo, int $colaborador): BoletaResource
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$ciclo->fecha_inicio->toDateString(), 'before_or_equal:'.$ciclo->fecha_fin->toDateString()],
            'minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'tasa' => ['required', Rule::in(['25', '35', '100'])],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $colaborador = Colaborador::withoutGlobalScopes()->findOrFail($colaborador);

        $boleta = $this->boletas->registrarHorasExtraEnBoleta(
            $empresa,
            $ciclo,
            $colaborador,
            $datos['fecha'],
            (int) $datos['minutos'],
            (string) $datos['tasa'],
            $datos['motivo'],
            (int) $request->user('api')->id,
        );

        return new BoletaResource($this->boletas->ver($empresa, $boleta));
    }

    public function aportesPrevisionales(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json($this->aportesPrevisionales->porCiclo($empresa, $ciclo));
    }

    public function aprobar(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource(
            $this->boletas->aprobar($empresa, $boleta, $request->user('api')->id)->load(['colaborador.empresa', 'conceptos.concepto']),
        );
    }

    /**
     * Pre-check para que el frontend muestre el detalle ANTES de intentar
     * aprobar (mismo patrón que CicloRemunerativoController::
     * incidenciasPendientesCierre) — BoletaService::aprobar() ya bloquea
     * igual del lado del servidor si se intenta aprobar sin consultar esto.
     */
    public function incidenciasPendientesAprobar(Request $request, Boleta $boleta): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return IncidenciaPendienteResource::collection(
            $this->boletas->incidenciasPendientesAprobar($empresa, collect([$boleta])),
        );
    }

    /**
     * Mismo pre-check que incidenciasPendientesAprobar() pero para el lote
     * completo de "aprobar seleccionadas" — el frontend lo consulta antes
     * de mostrar el diálogo de confirmación masivo.
     */
    public function incidenciasPendientesAprobarMasivo(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $boletas = Boleta::where('ciclo_id', $ciclo->id)->whereIn('id', $datos['ids'])->get();
        abort_if($boletas->count() !== count($datos['ids']), 404, 'Una o más boletas no pertenecen a este ciclo.');

        return IncidenciaPendienteResource::collection($this->boletas->incidenciasPendientesAprobar($empresa, $boletas));
    }

    /**
     * Selección masiva desde la planilla mensual (Sección "cerrar ciclo"):
     * reutiliza BoletaService::aprobar() por cada boleta dentro de una sola
     * transacción — misma regla de negocio que la aprobación individual
     * (solo "calculada" → "aprobada"), sin duplicarla. Si una sola boleta no
     * cumple la regla, toda la transacción se revierte (igual que
     * AsistenciaController::resolverIncidenciasMasivo()): el frontend debe
     * evitar seleccionar boletas que no estén en "calculada".
     */
    public function aprobarMasivo(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $boletas = Boleta::where('ciclo_id', $ciclo->id)->whereIn('id', $datos['ids'])->get();
        abort_if($boletas->count() !== count($datos['ids']), 404, 'Una o más boletas no pertenecen a este ciclo.');

        $usuarioId = $request->user('api')->id;
        DB::transaction(fn () => $boletas->each(fn ($boleta) => $this->boletas->aprobar($empresa, $boleta, $usuarioId)));

        return response()->json(['message' => 'Boletas aprobadas.', 'procesadas' => $boletas->count()]);
    }

    public function marcarPagada(Request $request, Boleta $boleta): BoletaResource
    {
        $datos = $request->validate([
            'referencia_pago' => ['nullable', 'string', 'max:255'],
        ]);

        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource(
            $this->boletas->marcarPagada($empresa, $boleta, $request->user('api')->id, $datos['referencia_pago'] ?? null)
                ->load(['colaborador.empresa', 'conceptos.concepto']),
        );
    }

    /**
     * Mismo patrón que aprobarMasivo(): reutiliza BoletaService::marcarPagada()
     * por cada boleta dentro de una sola transacción, sin duplicar la regla
     * de negocio (solo "aprobada" → "pagada"). Existe porque marcar el ciclo
     * completo como pagado (CicloRemunerativoService::marcarPagado) exige que
     * todas las boletas vigentes ya estén pagadas, y hacerlo una por una es
     * tedioso con muchos colaboradores.
     */
    public function pagarMasivo(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'referencia_pago' => ['nullable', 'string', 'max:255'],
        ]);

        $boletas = Boleta::where('ciclo_id', $ciclo->id)->whereIn('id', $datos['ids'])->get();
        abort_if($boletas->count() !== count($datos['ids']), 404, 'Una o más boletas no pertenecen a este ciclo.');

        $usuarioId = $request->user('api')->id;
        $referenciaPago = $datos['referencia_pago'] ?? null;
        DB::transaction(fn () => $boletas->each(fn ($boleta) => $this->boletas->marcarPagada($empresa, $boleta, $usuarioId, $referenciaPago)));

        return response()->json(['message' => 'Boletas marcadas como pagadas.', 'procesadas' => $boletas->count()]);
    }

    /**
     * Estructura E20/.4ta de PLAME — se completa manualmente cuando RR.HH.
     * recibe el recibo por honorarios real del locador; no se auto-genera
     * al calcular la boleta. indicador_retencion_4ta se DERIVA del cálculo
     * ya existente (nunca una segunda fórmula): basta con leer si la línea
     * RETENCION_RENTA_4TA de esta boleta tiene monto mayor a cero.
     */
    public function guardarComprobanteRh(Request $request, Boleta $boleta): JsonResponse
    {
        $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        abort_unless(
            $boleta->regimen_laboral_snapshot === 'Locacion de Servicios',
            422,
            'Esta boleta no corresponde a un recibo por honorarios.',
        );

        $datos = $request->validate([
            // Tabla 23 SUNAT — PENDIENTE DE CATÁLOGO SUNAT, no se valida
            // contra códigos reales todavía.
            'tipo_comprobante' => ['nullable', 'string', 'max:1'],
            'serie' => ['nullable', 'string', 'max:4'],
            'numero' => ['nullable', 'string', 'max:8'],
            'fecha_emision' => ['nullable', 'date'],
            'fecha_pago' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'indicador_retencion_regimen_pensionario' => ['nullable', Rule::in(['1', '2', '3'])],
            'importe_aporte_regimen_pensionario' => [
                'nullable', 'numeric', 'min:0',
                'required_if:indicador_retencion_regimen_pensionario,1',
                'required_if:indicador_retencion_regimen_pensionario,2',
            ],
        ]);

        $indicadorRetencion4ta = $boleta->conceptos()
            ->whereHas('concepto', fn ($q) => $q->where('codigo', 'RETENCION_RENTA_4TA'))
            ->where('monto', '>', 0)
            ->exists();

        $comprobante = BoletaComprobanteRh::updateOrCreate(
            ['boleta_id' => $boleta->id],
            [
                ...$datos,
                'monto_total_servicio' => app(BoletaService::class)->montoTotalServicioRh($boleta),
                'indicador_retencion_4ta' => $indicadorRetencion4ta,
                'registrado_por' => $request->user('api')->id,
            ],
        );

        return response()->json(['data' => $comprobante]);
    }
}
