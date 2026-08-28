<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\SunatMapeo;
use App\Modules\Nominas\Services\SunatCatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SunatCatalogoController extends Controller
{
    public function __construct(private SunatCatalogoService $catalogos) {}

    public function mapeos(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(SunatCatalogoService::TIPOS_VALIDOS)],
        ]);

        $mapeos = $this->catalogos->mapeosPorTipo($datos['tipo'])
            ->map(fn (SunatMapeo $mapeo) => [
                'id' => $mapeo->id,
                'tipo' => $mapeo->tipo,
                'clave_interna' => $mapeo->clave_interna,
                'codigo_sunat' => $mapeo->codigo_sunat,
                'descripcion_sunat' => $mapeo->descripcion_sunat,
                'activo' => $mapeo->activo,
                'motivo_estado' => $mapeo->motivo_estado,
                'estado' => SunatCatalogoService::calcularEstado(! $mapeo->activo, filled($mapeo->codigo_sunat), $mapeo->bloqueado_por_modelo),
            ])
            ->values();

        return response()->json(['data' => $mapeos]);
    }

    public function actualizarMapeo(Request $request, SunatMapeo $mapeo): JsonResponse
    {
        $datos = $request->validate([
            'codigo_sunat' => ['nullable', 'string', 'max:20'],
            'descripcion_sunat' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $mapeo = $this->catalogos->actualizarMapeo($mapeo, $datos, $request->user('api'));

        return response()->json(['data' => $mapeo]);
    }

    public function resumen(): JsonResponse
    {
        return response()->json($this->catalogos->resumen());
    }
}
