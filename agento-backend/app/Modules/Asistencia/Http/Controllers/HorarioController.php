<?php

namespace App\Modules\Asistencia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Http\Requests\ImportarHorariosRequest;
use App\Modules\Asistencia\Http\Requests\StoreHorarioRequest;
use App\Modules\Asistencia\Http\Requests\UpdateHorarioRequest;
use App\Modules\Asistencia\Http\Resources\HorarioResource;
use App\Modules\Asistencia\Infrastructure\HorarioPlantillaGenerator;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Services\HorarioService;
use App\Modules\Asistencia\Services\ImportarHorariosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HorarioController extends Controller
{
    public function __construct(
        private readonly HorarioService $horarios,
        private readonly ImportarHorariosService $importador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $paginador = $this->horarios->listar(
            $request->input('busqueda'),
            $request->input('estado'),
            $perPage,
        );

        return HorarioResource::collection($paginador)
            ->additional(['stats' => $this->horarios->estadisticas()]);
    }

    public function store(StoreHorarioRequest $request): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $horario = $this->horarios->crear($empresaActiva, $request->validated());

        return new HorarioResource($horario);
    }

    public function update(UpdateHorarioRequest $request, Horario $horario): HorarioResource
    {
        $horario = $this->horarios->actualizar($horario, $request->validated());

        return new HorarioResource($horario);
    }

    public function duplicar(Request $request, Horario $horario): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $copia = $this->horarios->duplicar($empresaActiva, $horario);

        return new HorarioResource($copia);
    }

    public function cambiarEstado(Horario $horario): HorarioResource
    {
        $horario = $this->horarios->cambiarEstado($horario);

        return new HorarioResource($horario);
    }

    public function plantillaImportacion(): StreamedResponse
    {
        $libro = (new HorarioPlantillaGenerator)->generar();
        $escritor = new Xlsx($libro);

        return response()->streamDownload(function () use ($escritor) {
            $escritor->save('php://output');
        }, 'plantilla-horarios.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function previsualizarImportacion(ImportarHorariosRequest $request): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;

        return response()->json([
            'data' => $this->importador->previsualizar($empresaActiva, $request->file('archivo')),
        ]);
    }

    public function importar(ImportarHorariosRequest $request): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;
        $resultado = $this->importador->importar($empresaActiva, $request->file('archivo'));

        return response()->json([
            'message' => "{$resultado['creados']} horarios creados, {$resultado['actualizados']} actualizados, {$resultado['omitidos']} omitidos.",
            'data' => $resultado,
        ]);
    }
}
