<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\TramoRenta;
use Illuminate\Http\JsonResponse;

class TramoRentaController extends Controller
{
    /**
     * Tabla legal única para todo el sistema (no varía por empresa ni
     * régimen) — mismo criterio de "última vigencia por categoría" que usa
     * ParametrosVigentesResolver para el cálculo real, así esta pantalla de
     * solo lectura siempre refleja exactamente lo que se está aplicando.
     */
    public function index(): JsonResponse
    {
        $categorias = TramoRenta::query()->select('categoria')->distinct()->pluck('categoria');

        $resultado = $categorias->mapWithKeys(function (string $categoria) {
            $vigenciaDesde = TramoRenta::where('categoria', $categoria)
                ->whereDate('vigencia_desde', '<=', now())
                ->max('vigencia_desde');

            if (! $vigenciaDesde) {
                return [$categoria => ['vigencia_desde' => null, 'tramos' => []]];
            }

            $tramos = TramoRenta::where('categoria', $categoria)
                ->where('vigencia_desde', $vigenciaDesde)
                ->orderBy('orden')
                ->get(['orden', 'limite_inferior_uit', 'limite_superior_uit', 'tasa_porcentaje']);

            return [$categoria => ['vigencia_desde' => $vigenciaDesde, 'tramos' => $tramos]];
        });

        return response()->json(['categorias' => $resultado]);
    }
}
