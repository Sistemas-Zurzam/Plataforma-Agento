<?php

namespace App\Modules\Personas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Personas\Http\Requests\StoreColaboradorRequest;
use App\Modules\Personas\Http\Resources\ColaboradorResource;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorDocumento;
use App\Modules\Personas\Services\ColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class ColaboradorController extends Controller
{
    public function __construct(private readonly ColaboradorService $colaboradores) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActiva = $request->user('api')->empresa;
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $paginador = $this->colaboradores->listar($empresaActiva, $request->input('busqueda'), $perPage);

        return ColaboradorResource::collection($paginador)
            ->additional(['stats' => $this->colaboradores->estadisticas($empresaActiva)]);
    }

    public function store(StoreColaboradorRequest $request): ColaboradorResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $colaborador = $this->colaboradores->crear($empresaActiva, $request->validated());

        return new ColaboradorResource($colaborador);
    }

    public function show(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $empresaActiva = $request->user('api')->empresa;

        return new ColaboradorResource(
            $this->colaboradores->obtenerDetalle($empresaActiva, $colaborador),
        );
    }

    public function calendarioDelMes(Request $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->colaboradores->calendarioDelMes(
            $request->user('api')->empresa,
            $colaborador,
            $datos['anio'],
            $datos['mes'],
        ));
    }

    public function actualizarCalendario(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'dias' => ['required', 'array', 'min:1'],
            'dias.*.fecha' => ['required', 'date'],
            'dias.*.tipo' => ['required', 'in:laborable_presencial,home_office,descanso,feriado'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->actualizarCalendario(
                $request->user('api')->empresa,
                $colaborador,
                $datos['dias'],
            ),
        );
    }

    public function actualizarHorario(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            'modalidad_trabajo' => ['required', 'in:presencial,remoto,hibrido'],
            'tolerancia_particular_minutos' => ['nullable', 'integer', 'min:0'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->actualizarHorario($request->user('api')->empresa, $colaborador, $datos),
        );
    }

    public function update(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => ['required', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular_colaborador' => ['required', 'string', 'max:20'],
            'celular_referencia' => ['required', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'cargo' => ['required', 'string', 'max:255'],
            'tipo_contrato' => ['required', Rule::in(['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'])],
            'regimen_laboral' => ['nullable', 'string', 'max:255'],
            'fecha_fin_contrato' => ['nullable', 'date', 'after_or_equal:'.$colaborador->fecha_ingreso->toDateString(), 'required_if:tipo_contrato,plazo_fijo'],
            'banco' => ['nullable', 'string', 'max:255'],
            'numero_cuenta' => ['nullable', 'string', 'max:255'],
            'tipo_cuenta' => ['nullable', Rule::in(['ahorro', 'corriente'])],
            'moneda_cuenta' => ['nullable', Rule::in(['PEN', 'USD'])],
            'cci' => ['nullable', 'string', 'max:20'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->actualizar($request->user('api')->empresa, $colaborador, $datos),
        );
    }

    public function cesar(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'fecha_cese' => ['required', 'date', 'after_or_equal:'.$colaborador->fecha_ingreso->toDateString()],
            'motivo_cese' => ['required', 'string', 'max:255'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->cesar($request->user('api')->empresa, $colaborador, $datos['fecha_cese'], $datos['motivo_cese']),
        );
    }

    public function destroy(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->colaboradores->eliminar($request->user('api')->empresa, $colaborador);

        return response()->json(['message' => 'Colaborador eliminado correctamente.']);
    }

    public function guardarDocumento(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['documento_identidad', 'recibo_servicio', 'contrato_firmado'])],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        return new ColaboradorResource($this->colaboradores->guardarDocumento(
            $request->user('api')->empresa,
            $colaborador,
            $datos['tipo'],
            $datos['archivo'],
            $request->user('api'),
        ));
    }

    public function verDocumento(Request $request, Colaborador $colaborador, ColaboradorDocumento $documento)
    {
        if (
            $colaborador->empresa_id !== $request->user('api')->empresa_id
            || $documento->colaborador_id !== $colaborador->id
        ) {
            abort(404);
        }

        abort_unless(Storage::disk('local')->exists($documento->ruta), 404, 'El archivo no está disponible.');

        return Storage::disk('local')->response(
            $documento->ruta,
            $documento->nombre_original,
            [],
            'inline',
        );
    }

    public function calendarioDefecto(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            'fecha_ingreso' => ['required', 'date'],
        ]);

        $empresaActiva = $request->user('api')->empresa;
        $horario = Horario::findOrFail($datos['horario_id']);

        return response()->json(
            $this->colaboradores->calendarioPorDefecto($empresaActiva, $horario, $datos['fecha_ingreso']),
        );
    }
}
