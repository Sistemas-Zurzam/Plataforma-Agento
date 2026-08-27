<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\BoletaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

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
}
