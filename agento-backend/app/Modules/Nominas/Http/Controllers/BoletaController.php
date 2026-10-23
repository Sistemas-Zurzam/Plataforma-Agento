<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\ReporteEjecutivo\ConceptosEfectivosPorBoleta;
use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Http\Resources\IncidenciaPendienteResource;
use App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export\ReporteEjecutivoRemuneracionesExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\AportesPrevisionalesService;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;

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
        $filas = array_map(
            fn (array $fila) => array_diff_key($fila, ['lineas' => true]),
            $this->resolverPrevisualizacion($request)['filas'],
        );

        return response()->json(['data' => $filas]);
    }

    /**
     * Proyectado de planilla en Excel, con el mismo formato que el Excel
     * del Resumen contable (una columna por concepto, bonos/HE/descuentos
     * separados, subtotales y hoja de resumen ejecutivo).
     */
    public function previsualizarExcel(Request $request): Response
    {
        $empresa = $request->user('api')->empresa;
        ['filas' => $filas, 'fecha_inicio' => $fechaInicio, 'hoy' => $hoy] = $this->resolverPrevisualizacion($request);

        $mes = Carbon::parse($fechaInicio);
        $periodo = ucfirst(mb_strtolower($mes->translatedFormat('F'), 'UTF-8')).' '.$mes->format('Y')
            .' (proyectado al '.Carbon::parse($hoy)->format('d/m/Y').')';

        $calculables = collect($filas)->where('estado', 'calculable');
        $filasReporte = $calculables->map(fn (array $fila) => [
            // detalle() indexa los conceptos por `boleta_id`; aquí no hay
            // boleta, se usa el colaborador.
            'boleta_id' => $fila['colaborador_id'],
            'empresa' => $empresa->nombre_comercial,
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
        $conceptos = ConceptosEfectivosPorBoleta::desdeResultados(
            $calculables->mapWithKeys(fn (array $fila) => [$fila['colaborador_id'] => $fila['lineas']])
        );
        $noCalculables = collect($filas)->where('estado', 'no_calculable')
            ->map(fn (array $fila) => ['nombre' => $fila['nombre'], 'cargo' => $fila['cargo'], 'motivo' => (string) $fila['motivo']])
            ->values()->all();

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generarDesdeFilas($periodo, $filasReporte, $conceptos, 'PROYECTADO DE PLANILLA', $noCalculables);
        $nombre = sprintf('Proyectado_planilla_%s_%s.xlsx', Str::slug($empresa->nombre_comercial), $hoy);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }

    /**
     * @return array{filas: array<int, array<string, mixed>>, fecha_inicio: string, fecha_fin: string, hoy: string}
     */
    private function resolverPrevisualizacion(Request $request): array
    {
        $empresa = $request->user('api')->empresa;
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $inicioMes = Carbon::create($datos['anio'], $datos['mes'], 1);
        $fechaInicio = $inicioMes->toDateString();
        $fechaFin = $inicioMes->copy()->endOfMonth()->toDateString();
        $hoy = app(FechaOperativa::class)->hoy()->toDateString();
        $fechaCorte = $fechaFin;

        // Si el mes ya tiene un ciclo se usan sus mismas fechas (incluido un
        // corte diferido, p. ej. el 27) y sus bonos/descuentos registrados,
        // para coincidir con la planilla real. La asistencia se lee solo
        // hasta el último día procesado de cada colaborador; el resto del
        // mes se proyecta al sueldo (ver BoletaService::previsualizarPlanilla()).
        $ciclo = CicloRemunerativo::where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $fechaFin)->whereDate('fecha_fin', '>=', $fechaInicio)
            ->orderBy('fecha_inicio')->first();
        if ($ciclo) {
            [$fechaInicio, $fechaFin, $fechaCorte] = [$ciclo->fecha_inicio->toDateString(), $ciclo->fecha_fin->toDateString(), $ciclo->fecha_corte_asistencia->toDateString()];
        }

        return [
            'filas' => $this->boletas->previsualizarPlanilla($empresa, $fechaInicio, $fechaFin, $fechaCorte, Carbon::parse($hoy), $fechaFin, $ciclo?->id),
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'hoy' => $hoy,
        ];
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
