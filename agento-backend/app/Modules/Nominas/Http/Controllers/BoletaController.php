<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\BoletaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BoletaController extends Controller
{
    public function __construct(private readonly BoletaService $boletas) {}

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
        $empresa = $request->user('api')->empresa;
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $inicioMes = Carbon::create($datos['anio'], $datos['mes'], 1);
        $fechaInicio = $inicioMes->toDateString();
        $fechaFin = $inicioMes->copy()->endOfMonth()->toDateString();
        // Un mes en curso se previsualiza "hasta hoy"; un mes ya cerrado se
        // previsualiza completo — nunca se proyecta hacia el futuro.
        $fechaCorte = min($fechaFin, now()->toDateString());

        return response()->json([
            'data' => $this->boletas->previsualizarPlanilla($empresa, $fechaInicio, $fechaFin, $fechaCorte),
        ]);
    }

    public function index(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $tipo = $request->input('tipo');

        return BoletaResource::collection(
            $this->boletas->listar($empresa, $ciclo, max(1, min((int) $request->input('per_page', 25), 100)), $tipo),
        );
    }

    public function show(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource($this->boletas->ver($empresa, $boleta));
    }

    public function resumen(Request $request, CicloRemunerativo $ciclo)
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json($this->boletas->resumen($empresa, $ciclo, $request->input('tipo')));
    }

    public function aprobar(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource(
            $this->boletas->aprobar($empresa, $boleta, $request->user('api')->id)->load(['colaborador.empresa', 'conceptos.concepto']),
        );
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
            'referencia_pago' => ['required', 'string', 'max:255'],
        ]);

        $empresa = $this->empresaAutorizadaDeLaBoleta($request, $boleta);

        return new BoletaResource(
            $this->boletas->marcarPagada($empresa, $boleta, $request->user('api')->id, $datos['referencia_pago'])
                ->load(['colaborador.empresa', 'conceptos.concepto']),
        );
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
                'indicador_retencion_4ta' => $indicadorRetencion4ta,
                'registrado_por' => $request->user('api')->id,
            ],
        );

        return response()->json(['data' => $comprobante]);
    }
}
