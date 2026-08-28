<?php

namespace App\Modules\Asistencia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Models\TipoAusencia;
use App\Modules\Nominas\Services\SunatCatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoAusenciaController extends Controller
{
    private const CAMPOS_BASE = ['id', 'codigo', 'nombre', 'codigo_sunat_suspension', 'descripcion_sunat', 'activo', 'sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado'];

    /**
     * Catálogo global de tipos de ausencia (Catálogos SUNAT → Suspensiones)
     * — alimenta el mapeo hacia la Tabla 21 de SUNAT.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TipoAusencia::orderBy('id')->get(self::CAMPOS_BASE)->map(fn (TipoAusencia $t) => $this->conEstado($t)),
        ]);
    }

    public function actualizarCodigoSunat(Request $request, TipoAusencia $tipoAusencia): JsonResponse
    {
        $datos = $request->validate([
            'codigo_sunat_suspension' => ['nullable', 'string', 'max:20'],
            'descripcion_sunat' => ['nullable', 'string', 'max:255'],
        ]);

        $tipoAusencia->update($datos);

        return response()->json(['data' => $this->conEstado($tipoAusencia)]);
    }

    private function conEstado(TipoAusencia $tipoAusencia): array
    {
        return [
            ...$tipoAusencia->only(self::CAMPOS_BASE),
            'estado' => SunatCatalogoService::calcularEstado(
                $tipoAusencia->sunat_no_aplica,
                filled($tipoAusencia->codigo_sunat_suspension),
                $tipoAusencia->sunat_bloqueado_por_modelo,
            ),
        ];
    }
}
