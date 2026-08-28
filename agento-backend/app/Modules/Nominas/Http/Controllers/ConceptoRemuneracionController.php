<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\ConceptoRemuneracionService;
use App\Modules\Nominas\Services\SunatCatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConceptoRemuneracionController extends Controller
{
    private const CAMPOS_BASE = [
        'id', 'codigo', 'nombre', 'tipo', 'es_remunerativo_laboral', 'afecta_renta_5ta', 'codigo_plame',
        'sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado',
    ];

    public function __construct(private ConceptoRemuneracionService $conceptos) {}

    /**
     * Catálogo único de conceptos — el frontend NUNCA decide si un código es
     * ingreso/egreso o si es remunerativo; solo lo lee de acá para mostrarlo
     * y para elegir el concepto_id correcto al registrar una comisión/bono.
     */
    public function index(): JsonResponse
    {
        $conceptos = ConceptoRemuneracion::where('activo', true)
            ->get(self::CAMPOS_BASE)
            ->map(fn (ConceptoRemuneracion $c) => $this->conEstado($c));

        return response()->json(['data' => $conceptos]);
    }

    /**
     * A diferencia de es_remunerativo_laboral/afecta_renta_5ta (que
     * alimentan directamente el motor de cálculo y por eso el catálogo
     * sigue siendo de solo lectura desde la UI), codigo_plame no afecta
     * ningún monto — es puramente el código que PLAME (SUNAT, Tabla 22)
     * usa para identificar este concepto en el archivo .rem. Editable en
     * cuanto se cuente con el catálogo oficial; hasta entonces queda NULL
     * ("pendiente de catálogo SUNAT").
     */
    public function actualizarCodigoPlame(Request $request, ConceptoRemuneracion $concepto): JsonResponse
    {
        $datos = $request->validate([
            // Tabla 22 (Anexo 3, E18): "Código de concepto remunerativo y/o
            // no remunerativo — Numérico — Longitud 4". Se acepta 1-4
            // dígitos (un administrador escribe "121" de forma natural) y
            // el Service lo normaliza a 4 dígitos con cero inicial ("0121")
            // antes de guardar — la representación canónica siempre lleva
            // el cero, nunca se pierde.
            'codigo_plame' => ['nullable', 'digits_between:1,4'],
            'descripcion_sunat' => ['nullable', 'string', 'max:255'],
            'vigencia_desde' => ['nullable', 'date'],
        ]);

        $concepto = $this->conceptos->actualizarCodigoPlame($concepto, $datos, $request->user('api'));

        return response()->json(['data' => $this->conEstado($concepto)]);
    }

    private function conEstado(ConceptoRemuneracion $concepto): array
    {
        return [
            ...$concepto->only(self::CAMPOS_BASE),
            'estado' => SunatCatalogoService::calcularEstado(
                $concepto->sunat_no_aplica,
                filled($concepto->codigo_plame),
                $concepto->sunat_bloqueado_por_modelo,
            ),
        ];
    }

    /**
     * Historial de códigos PLAME de un concepto (Catálogos SUNAT → Conceptos
     * PLAME) — nunca se pierde un valor anterior, solo se agregan filas.
     */
    public function historialCodigoPlame(ConceptoRemuneracion $concepto): JsonResponse
    {
        $historial = $this->conceptos->historialCodigoPlame($concepto)->map(fn ($fila) => [
            'id' => $fila->id,
            'codigo_plame' => $fila->codigo_plame,
            'descripcion_sunat' => $fila->descripcion_sunat,
            'vigencia_desde' => $fila->vigencia_desde->toDateString(),
            'actualizado_por' => $fila->actualizadoPor?->name,
            'creado_en' => $fila->created_at->toDateTimeString(),
        ])->values();

        return response()->json(['data' => $historial]);
    }
}
