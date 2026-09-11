<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BonoAsistenciaLote;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\BonoAsistenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BonoAsistenciaController extends Controller
{
    public function __construct(private readonly BonoAsistenciaService $service) {}

    public function generar(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        // Mismo criterio que PlanillaComplementariaController::agregarConcepto():
        // BONIFICACION/BONO_NO_REMUNERATIVO son demasiado genéricos para
        // Tabla 22 sin una clasificación PLAME concreta.
        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);

        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $requiereDefinicion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $lote = $this->service->generar(
            $this->empresa($request, $ciclo),
            $ciclo,
            (int) $datos['concepto_id'],
            isset($datos['concepto_definicion_id']) ? (int) $datos['concepto_definicion_id'] : null,
            $datos['nombre'],
            $datos['motivo'] ?? null,
            $request->user('api')->id,
        );

        return response()->json(['data' => $lote], 201);
    }

    public function index(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresa($request, $ciclo);

        $lotes = BonoAsistenciaLote::where('ciclo_id', $ciclo->id)
            ->where('empresa_id', $empresa->id)
            ->with('detalles')
            ->latest()
            ->get();

        return response()->json(['data' => $lotes]);
    }

    public function show(Request $request, BonoAsistenciaLote $lote): JsonResponse
    {
        $this->empresaLote($request, $lote);

        return response()->json(['data' => $lote->load('detalles.colaborador')]);
    }

    public function exportar(Request $request, BonoAsistenciaLote $lote): Response
    {
        $empresa = $this->empresaLote($request, $lote);

        $contenido = $this->service->exportarExcel($empresa, $lote, $request->user('api')->id);
        $nombre = $lote->archivo_exportado_nombre ?? 'bono-asistencia.xlsx';

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }

    public function importar(Request $request, BonoAsistenciaLote $lote): JsonResponse
    {
        $empresa = $this->empresaLote($request, $lote);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $resumen = $this->service->importarExcel($empresa, $lote, $request->file('archivo'), $request->user('api')->id);

        return response()->json(['data' => $resumen]);
    }

    public function aplicar(Request $request, BonoAsistenciaLote $lote): JsonResponse
    {
        $empresa = $this->empresaLote($request, $lote);

        $lote = $this->service->aplicar($empresa, $lote, $request->user('api')->id);

        return response()->json(['data' => $lote]);
    }

    public function anular(Request $request, BonoAsistenciaLote $lote): JsonResponse
    {
        $empresa = $this->empresaLote($request, $lote);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->service->anular($empresa, $lote, $datos['motivo'], $request->user('api')->id);

        // Mismo criterio que PlanillaComplementariaController::eliminar():
        // 200 con data null, no un 204 sin cuerpo.
        return response()->json(['data' => null]);
    }

    private function empresa(Request $request, CicloRemunerativo $ciclo): Empresa
    {
        abort_unless($request->user('api')->tieneAccesoA($ciclo->empresa), 403);

        return $ciclo->empresa;
    }

    private function empresaLote(Request $request, BonoAsistenciaLote $lote): Empresa
    {
        abort_unless($request->user('api')->tieneAccesoA($lote->empresa), 403);

        return $lote->empresa;
    }
}
