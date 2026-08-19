<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Services\BeneficioSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeneficioSocialController extends Controller
{
    public function __construct(private readonly BeneficioSocialService $beneficios) {}

    private function validarTipoAnio(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', 'in:gratificacion_julio,gratificacion_diciembre,cts_mayo,cts_noviembre'],
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);
    }

    public function resumen(Request $request): JsonResponse
    {
        $datos = $this->validarTipoAnio($request);
        $empresa = $request->user('api')->empresa;

        return response()->json($this->beneficios->resumen($empresa, $datos['tipo'], $datos['anio']));
    }

    public function calcular(Request $request): JsonResponse
    {
        $datos = $this->validarTipoAnio($request);
        $empresa = $request->user('api')->empresa;

        $this->beneficios->calcular($empresa, $datos['tipo'], $datos['anio'], $request->user('api')->id);

        return response()->json($this->beneficios->resumen($empresa, $datos['tipo'], $datos['anio']));
    }

    public function marcarPagado(Request $request, BeneficioSocial $beneficio): JsonResponse
    {
        $datos = $request->validate([
            'referencia_pago' => ['required', 'string', 'max:255'],
        ]);

        $empresa = $request->user('api')->empresa;
        $this->beneficios->marcarPagado($empresa, $beneficio, $request->user('api')->id, $datos['referencia_pago']);

        return response()->json($this->beneficios->resumen($empresa, $beneficio->tipo, $beneficio->anio));
    }
}
