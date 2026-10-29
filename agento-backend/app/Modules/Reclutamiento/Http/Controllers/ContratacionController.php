<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Application\RegistrarPeriodoPrueba;
use App\Modules\Personas\Models\ColaboradorPeriodoPrueba;
use App\Modules\Reclutamiento\Application\ContratacionService;
use App\Modules\Reclutamiento\Http\Requests\ContratarRequest;
use App\Modules\Reclutamiento\Http\Resources\PostulacionResource;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContratacionController extends SeleccionController
{
    public function __construct(
        private readonly ContratacionService $contrataciones,
        private readonly RegistrarPeriodoPrueba $periodos,
    ) {}

    /**
     * Catálogos para el formulario de contratación (sedes de la empresa
     * activa y horarios vigentes, que son un catálogo global en Agento).
     */
    public function opciones(Request $request): JsonResponse
    {
        $empresaId = $this->alcance($request)->empresaId;

        return response()->json(['data' => [
            'sedes' => Sede::where('empresa_id', $empresaId)->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'horarios' => Horario::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo_turno']),
        ]]);
    }

    public function contratar(ContratarRequest $request, Postulacion $postulacion): JsonResponse
    {
        $resultado = $this->contrataciones->contratar($this->alcance($request), $postulacion, $request->validated());

        return response()->json(['data' => [
            ...$resultado,
            'postulacion' => (new PostulacionResource($resultado['postulacion']->load(['candidato', 'proceso'])))->resolve($request),
            'plaza' => ['numero' => $resultado['plaza']->numero, 'estado' => $resultado['plaza']->estado],
        ]], 201);
    }

    /**
     * Resultado del período de prueba del colaborador contratado vía esta
     * postulación (el registro vive en Personas).
     */
    public function resultadoPeriodoPrueba(Request $request, Postulacion $postulacion): JsonResponse
    {
        $datos = $request->validate([
            'resultado' => ['required', Rule::in(['superado', 'no_superado', 'en_curso'])],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);

        $periodo = ColaboradorPeriodoPrueba::where('empresa_id', $this->alcance($request)->empresaId)
            ->where('colaborador_id', $postulacion->colaborador_id)
            ->latest('id')
            ->firstOrFail();

        return response()->json(['data' => $this->periodos->registrarResultado($periodo, $datos['resultado'], $datos['observacion'] ?? null)]);
    }
}
