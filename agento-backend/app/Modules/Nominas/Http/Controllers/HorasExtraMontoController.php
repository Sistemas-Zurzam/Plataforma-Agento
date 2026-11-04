<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Http\Requests\ResumenAsistenciaRequest;
use App\Modules\Asistencia\Infrastructure\HorasExtraExcelExporter;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Nominas\Application\EstimarMontoHorasExtra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Montos referenciales de horas extra para la pestaña de Asistencia. Vive en
 * Nóminas (sueldos y tasas son de este módulo) y exige nominas.ver: quien
 * solo gestiona asistencia no ve importes. La empresa es la activa del JWT.
 */
class HorasExtraMontoController extends Controller
{
    public function __construct(private readonly EstimarMontoHorasExtra $estimador) {}

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        return response()->json([
            'data' => $this->estimador->paraIds($request->user('api')->empresa, $datos['ids']),
        ]);
    }

    /**
     * Mismo Excel que Asistencia (mismas filas y filtros) con la columna de
     * monto aproximado. Asistencia no conoce importes: Nóminas los agrega.
     */
    public function excel(ResumenAsistenciaRequest $request, AsistenciaOperacionService $operaciones): Response
    {
        $empresa = $request->user('api')->empresa;
        $filtros = $request->validated();

        $horasExtra = $operaciones->horasExtraParaExportar($empresa, $filtros);
        $montos = $this->estimador->paraIds($empresa, $horasExtra->pluck('id')->all());
        $contenido = HorasExtraExcelExporter::generar($empresa, $filtros['fecha_desde'], $filtros['fecha_hasta'], $horasExtra, $montos);

        $colaboradores = $horasExtra->pluck('colaborador')->filter()->unique('id');
        $sufijo = $colaboradores->count() === 1
            ? Str::slug(trim($colaboradores->first()->nombres.' '.$colaboradores->first()->apellidos))
            : Str::slug($empresa->nombre_comercial);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.sprintf('Horas_extra_%s_%s.xlsx', $sufijo, $filtros['fecha_desde']).'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }
}
