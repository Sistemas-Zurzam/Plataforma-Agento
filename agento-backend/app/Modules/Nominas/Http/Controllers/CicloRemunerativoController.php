<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Http\Resources\CicloRemunerativoResource;
use App\Modules\Nominas\Http\Resources\ColaboradorConceptoPeriodoResource;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CicloRemunerativoController extends Controller
{
    public function __construct(
        private readonly CicloRemunerativoService $ciclos,
        private readonly BoletaService $boletas,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresa = $request->user('api')->empresa;

        return CicloRemunerativoResource::collection(
            $this->ciclos->listar($empresa, max(1, min((int) $request->input('per_page', 15), 50))),
        );
    }

    public function store(Request $request): CicloRemunerativoResource
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'periodicidad' => ['nullable', 'in:mensual'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_corte_asistencia' => ['required', 'date'],
            'fecha_pago' => ['required', 'date', 'after_or_equal:fecha_fin'],
        ]);

        $empresa = $request->user('api')->empresa;
        $ciclo = $this->ciclos->crear($empresa, $datos, $request->user('api')->id);

        return new CicloRemunerativoResource($ciclo);
    }

    public function calcular(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        $motivo = $request->input('motivo_recalculo');

        $this->boletas->iniciarCalculoAsync($empresa, $ciclo, $request->user('api')->id, $motivo);

        return response()->json([
            'message' => 'El cálculo se está procesando en segundo plano.',
            'calculo_estado' => 'en_proceso',
        ], 202);
    }

    public function estadoCalculo(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        abort_unless($ciclo->empresa_id === $empresa->id, 403, 'Este ciclo no pertenece a la empresa activa.');

        return response()->json([
            'calculo_estado' => $ciclo->calculo_estado,
            'calculo_iniciado_at' => $ciclo->calculo_iniciado_at?->toDateTimeString(),
            'calculo_finalizado_at' => $ciclo->calculo_finalizado_at?->toDateTimeString(),
            'calculo_resultado' => $ciclo->calculo_resultado,
        ]);
    }

    public function cerrar(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $request->user('api')->empresa;

        return new CicloRemunerativoResource($this->ciclos->cerrar($empresa, $ciclo));
    }

    public function reabrir(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $request->user('api')->empresa;

        return new CicloRemunerativoResource($this->ciclos->reabrir($empresa, $ciclo));
    }

    public function marcarPagado(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $request->user('api')->empresa;

        return new CicloRemunerativoResource($this->ciclos->marcarPagado($empresa, $ciclo));
    }

    public function listarConceptos(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador): AnonymousResourceCollection
    {
        $empresa = $request->user('api')->empresa;

        return ColaboradorConceptoPeriodoResource::collection(
            $this->ciclos->listarConceptos($empresa, $ciclo, $colaborador),
        );
    }

    public function registrarConcepto(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador): ColaboradorConceptoPeriodoResource
    {
        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $empresa = $request->user('api')->empresa;
        $item = $this->ciclos->registrarConcepto($empresa, $ciclo, $colaborador, $datos, $request->user('api')->id);

        return new ColaboradorConceptoPeriodoResource($item->load('concepto'));
    }
}
