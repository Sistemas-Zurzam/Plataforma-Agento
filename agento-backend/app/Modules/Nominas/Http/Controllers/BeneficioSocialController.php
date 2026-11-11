<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
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
        if (!$empresa || !$request->user('api')->tieneAccesoA($empresa)) abort(404);

        return response()->json($this->beneficios->resumen($empresa, $datos['tipo'], $datos['anio']));
    }

    public function calcular(Request $request): JsonResponse
    {
        $datos = $this->validarTipoAnio($request);
        $empresa = $request->user('api')->empresa;
        if (!$empresa || !$request->user('api')->tieneAccesoA($empresa)) abort(404);

        $this->beneficios->calcular($empresa, $datos['tipo'], $datos['anio'], $request->user('api')->id);

        return response()->json($this->beneficios->resumen($empresa, $datos['tipo'], $datos['anio']));
    }

    public function marcarPagado(Request $request, BeneficioSocial $beneficio): JsonResponse
    {
        $datos = $request->validate([
            'referencia_pago' => ['required', 'string', 'max:255'],
        ]);

        $empresa = $request->user('api')->empresa;
        if (!$empresa || !$request->user('api')->tieneAccesoA($empresa)) abort(404);
        if (str_starts_with($beneficio->tipo, 'cts_')) {
            return response()->json(['message'=>'Para CTS registre y concilie cada depósito por trabajador.','errors'=>['estado'=>['El pago global de CTS está deshabilitado.']]],422);
        }
        $this->beneficios->marcarPagado($empresa, $beneficio, $request->user('api')->id, $datos['referencia_pago']);

        return response()->json($this->beneficios->resumen($empresa, $beneficio->tipo, $beneficio->anio));
    }

    public function registrarDeposito(Request $request, BeneficioSocialDetalle $detalle): JsonResponse
    {
        $datos=$request->validate(['fecha_deposito'=>['required','date'],'importe'=>['required','numeric','gt:0'],'entidad'=>['required','string','max:160'],'referencia'=>['required','string','max:160'],'constancia'=>['nullable','string','max:2048']]);
        $empresa=$request->user('api')->empresa;
        if (!$empresa || !$request->user('api')->tieneAccesoA($empresa)) abort(404);
        $this->beneficios->registrarDeposito($empresa,$detalle,$request->user('api')->id,$datos);
        return response()->json($this->beneficios->resumen($empresa,$detalle->beneficioSocial->tipo,$detalle->beneficioSocial->anio));
    }

    public function registrarDepositoCts(Request $request, BeneficioSocialDetalle $detalle): JsonResponse
    {
        return $this->registrarDeposito($request,$detalle);
    }
}
