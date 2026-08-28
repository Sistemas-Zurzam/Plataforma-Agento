<?php

namespace App\Modules\Personas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Http\Requests\ImportarColaboradoresRequest;
use App\Modules\Personas\Http\Requests\StoreColaboradorRequest;
use App\Modules\Personas\Http\Resources\ColaboradorResource;
use App\Modules\Personas\Infrastructure\ColaboradorPlantillaGenerator;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorDocumento;
use App\Modules\Personas\Services\ColaboradorService;
use App\Modules\Personas\Services\ImportarColaboradoresService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ColaboradorController extends Controller
{
    public function __construct(
        private readonly ColaboradorService $colaboradores,
        private readonly ImportarColaboradoresService $importador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaIds = $this->resolverEmpresaIds($request);
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $paginador = $this->colaboradores->listar($empresaIds, $request->input('busqueda'), $perPage);

        return ColaboradorResource::collection($paginador)
            ->additional(['stats' => $this->colaboradores->estadisticas($empresaIds)]);
    }

    public function rotativosSinRol(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json([
            'data' => $this->colaboradores->colaboradoresRotativosSinRol(
                $this->resolverEmpresaIds($request), $datos['anio'], $datos['mes'],
            ),
        ]);
    }

    /**
     * "Todas las empresas" nunca acepta IDs del frontend — se resuelve acá
     * contra las empresas realmente autorizadas del usuario
     * (empresa_usuario), salvo que sea administrador global, que ve
     * literalmente todas las del sistema (ver User::esAdministradorGlobal).
     *
     * @return array<int, int>
     */
    private function resolverEmpresaIds(Request $request): array
    {
        $usuario = $request->user('api');

        return $request->boolean('todas_empresas')
            ? ($usuario->esAdministradorGlobal()
                ? Empresa::pluck('id')->all()
                : $usuario->empresas()->pluck('empresas.id')->all())
            : [$usuario->empresa->id];
    }

    /**
     * Igual que CicloRemunerativoController::empresaAutorizadaDelCiclo(): el
     * binding de {colaborador} (ver Colaborador::resolveRouteBinding) ya
     * permite resolver cualquier colaborador de las empresas que el usuario
     * realmente administra, no solo la empresa activa — por ejemplo, al
     * editar la configuración de nómina de un colaborador cuyo ciclo
     * remunerativo pertenece a otra empresa autorizada (ver selector de
     * "Planilla mensual"). Los métodos que operan sobre un colaborador ya
     * resuelto deben usar SU empresa, no la activa de la sesión.
     */
    private function empresaAutorizadaDelColaborador(Request $request, Colaborador $colaborador): Empresa
    {
        $empresa = $colaborador->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a la empresa de este colaborador.');

        return $empresa;
    }

    public function store(StoreColaboradorRequest $request): JsonResponse|ColaboradorResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $datos = $request->validated();

        // El unique de (empresa_id, tipo_documento, numero_documento) en BD
        // no distingue soft-deleted — si no se detecta acá antes, un
        // colaborador eliminado con el mismo documento revienta el insert
        // con un error de base de datos genérico en vez de una respuesta
        // clara con la opción de restaurarlo.
        $eliminado = Colaborador::onlyTrashed()
            ->where('empresa_id', $empresaActiva->id)
            ->where('tipo_documento', $datos['tipo_documento'])
            ->where('numero_documento', $datos['numero_documento'])
            ->first();

        if ($eliminado) {
            return response()->json([
                'message' => 'Ya existe un colaborador eliminado con este documento en esta empresa.',
                'colaborador_eliminado' => [
                    'id' => $eliminado->id,
                    'nombre_completo' => "{$eliminado->nombres} {$eliminado->apellidos}",
                    'legajo' => $eliminado->legajo,
                    'fecha_ingreso' => $eliminado->fecha_ingreso?->toDateString(),
                    'eliminado_at' => $eliminado->deleted_at?->toDateString(),
                ],
            ], 409);
        }

        $colaborador = $this->colaboradores->crear($empresaActiva, $datos);

        return new ColaboradorResource($colaborador);
    }

    public function restaurar(Request $request, int $colaborador): ColaboradorResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $colaboradorRestaurado = $this->colaboradores->restaurar($empresaActiva, $colaborador);

        return new ColaboradorResource($colaboradorRestaurado);
    }

    public function show(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $empresa = $this->empresaAutorizadaDelColaborador($request, $colaborador);

        return new ColaboradorResource(
            $this->colaboradores->obtenerDetalle($empresa, $colaborador),
        );
    }

    public function calendarioDelMes(Request $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->colaboradores->calendarioDelMes(
            $this->empresaAutorizadaDelColaborador($request, $colaborador),
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
                $this->empresaAutorizadaDelColaborador($request, $colaborador),
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
            'dias_descanso_rotativo_por_semana' => ['nullable', 'integer', 'min:1', 'max:6'],
            // A partir de qué fecha este horario afecta el procesamiento de
            // marcaciones (Sección 10) — no puede ser futura: eso dejaría
            // "colaborador.horario_id" (el puntero de conveniencia al
            // horario actual) apuntando a un horario que todavía no aplica.
            'vigencia_desde' => ['required', 'date', 'before_or_equal:today'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'],
        ]);

        $horarioSeleccionado = Horario::find($datos['horario_id']);
        if ($horarioSeleccionado?->tipo_turno === 'rotativo' && empty($datos['dias_descanso_rotativo_por_semana'])) {
            throw ValidationException::withMessages([
                'dias_descanso_rotativo_por_semana' => 'Indica cuántos días de descanso a la semana le corresponden — este horario es rotativo y el sistema nunca lo adivina.',
            ]);
        }

        return new ColaboradorResource(
            $this->colaboradores->actualizarHorario($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador, $datos),
        );
    }

    public function actualizarRemuneracion(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'salario' => ['required', 'numeric', 'min:0'],
            'moneda_salario' => ['nullable', Rule::in(['PEN', 'USD'])],
            'periodicidad_pago' => ['nullable', Rule::in(['mensual', 'quincenal', 'semanal'])],
            'asignacion_familiar' => ['nullable', 'numeric', 'min:0'],
            'vigencia_desde' => ['required', 'date'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->actualizarRemuneracion($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador, $datos),
        );
    }

    public function actualizarConfiguracionNomina(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $esHonorarios = $request->input('regimen_laboral') === 'Locacion de Servicios';

        $datos = $request->validate([
            'regimen_laboral' => ['nullable', Rule::in(Colaborador::REGIMENES_LABORALES)],
            // ONP/AFP no aplica a un locador (no hay relación laboral) — solo
            // es obligatorio para regímenes de planilla dependiente.
            'sistema_previsional' => [$esHonorarios ? 'nullable' : 'required', 'string'],
            'afp_id' => ['nullable', 'integer', 'exists:afps,id', ...($esHonorarios ? [] : ['required_unless:sistema_previsional,onp'])],
            'tipo_comision' => ['nullable', Rule::in(['flujo', 'mixta']), ...($esHonorarios ? [] : ['required_unless:sistema_previsional,onp'])],
            // El E5 de PLAME exige el CUSPP con exactamente 12 caracteres.
            'cuspp' => ['nullable', 'string', 'size:12', ...($esHonorarios ? [] : ['required_unless:sistema_previsional,onp'])],
            'tiene_hijos_asignacion_familiar' => ['nullable', 'boolean'],
            'tiene_suspension_renta_4ta' => ['nullable', 'boolean'],
        ]);

        if (! $esHonorarios && $datos['sistema_previsional'] !== 'onp') {
            $afpValida = Afp::where('id', $datos['afp_id'])->where('clave', $datos['sistema_previsional'])->exists();
            abort_unless($afpValida, 422, 'El sistema previsional no corresponde a la AFP seleccionada.');
        }

        return new ColaboradorResource(
            $this->colaboradores->actualizarConfiguracionNomina($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador, $datos),
        );
    }

    public function update(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            // "ruc" solo aplica a locadores — tipo_trabajador no se edita
            // acá, así que se valida contra el valor YA guardado.
            'tipo_documento' => [
                'required',
                $colaborador->tipo_trabajador === 'locador' ? Rule::in(['dni', 'ce', 'pasaporte', 'ruc']) : Rule::in(['dni', 'ce', 'pasaporte']),
            ],
            'numero_documento' => [
                'required', 'string',
                $request->input('tipo_documento') === 'ruc' ? 'digits:11' : 'max:20',
            ],
            'fecha_nacimiento' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular_colaborador' => ['required', 'string', 'max:20'],
            'celular_referencia' => ['required', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'cargo' => ['required', 'string', 'max:255'],
            'tipo_contrato' => ['required', Rule::in(['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'])],
            'regimen_laboral' => ['nullable', Rule::in(Colaborador::REGIMENES_LABORALES)],
            // tipo_trabajador no se edita acá (sigue siendo create-only) —
            // la categoría solo aplica si el colaborador YA es "trabajador".
            'categoria_trabajador' => [
                $colaborador->tipo_trabajador === 'trabajador' ? 'required' : 'prohibited',
                Rule::in(Colaborador::CATEGORIAS_TRABAJADOR),
            ],
            'fecha_fin_contrato' => ['nullable', 'date', 'after_or_equal:'.$colaborador->fecha_ingreso->toDateString(), 'required_if:tipo_contrato,plazo_fijo'],
            // V3 P3 — antes solo editable en Crear.
            'es_trabajador_confianza' => ['nullable', 'boolean'],
            // V3 P2 — "contabilizar_tardanzas" ahora tiene efecto real en
            // nómina (ver CalcularBoletaColaborador), por eso pasa a ser
            // editable acá igual que en Crear. contabilizar_horas_extra se
            // homologa por consistencia de formulario (P1), pero sigue sin
            // efecto en el cálculo — huérfano, fuera de alcance de esta fase.
            'contabilizar_tardanzas' => ['nullable', 'boolean'],
            'contabilizar_horas_extra' => ['nullable', 'boolean'],
            'banco' => ['nullable', 'string', 'max:255'],
            'numero_cuenta' => ['nullable', 'string', 'max:255'],
            'tipo_cuenta' => ['nullable', Rule::in(['ahorro', 'corriente'])],
            'moneda_cuenta' => ['nullable', Rule::in(['PEN', 'USD'])],
            'cci' => ['nullable', 'string', 'max:20'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->actualizar($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador, $datos),
        );
    }

    public function cesar(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'fecha_cese' => ['required', 'date', 'after_or_equal:'.$colaborador->fecha_ingreso->toDateString()],
            'motivo_cese' => ['required', 'string', 'max:255'],
        ]);

        return new ColaboradorResource(
            $this->colaboradores->cesar($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador, $datos['fecha_cese'], $datos['motivo_cese']),
        );
    }

    public function destroy(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->colaboradores->eliminar($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador);

        return response()->json(['message' => 'Colaborador eliminado correctamente.']);
    }

    public function guardarDocumento(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['documento_identidad', 'recibo_servicio', 'contrato_firmado'])],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        return new ColaboradorResource($this->colaboradores->guardarDocumento(
            $this->empresaAutorizadaDelColaborador($request, $colaborador),
            $colaborador,
            $datos['tipo'],
            $datos['archivo'],
            $request->user('api'),
        ));
    }

    public function verDocumento(Request $request, Colaborador $colaborador, ColaboradorDocumento $documento)
    {
        if (
            ! $request->user('api')->tieneAccesoA($colaborador->empresa)
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

    public function guardarFotoPerfil(Request $request, Colaborador $colaborador): ColaboradorResource
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return new ColaboradorResource($this->colaboradores->guardarFotoPerfil(
            $this->empresaAutorizadaDelColaborador($request, $colaborador),
            $colaborador,
            $datos['archivo'],
            $request->user('api'),
        ));
    }

    public function verFotoPerfil(Request $request, Colaborador $colaborador)
    {
        $documento = $this->colaboradores->obtenerFotoPerfil($this->empresaAutorizadaDelColaborador($request, $colaborador), $colaborador);

        abort_unless($documento && Storage::disk('local')->exists($documento->ruta), 404, 'Este colaborador no tiene foto de perfil.');

        return Storage::disk('local')->response($documento->ruta, $documento->nombre_original, [], 'inline');
    }

    public function calendarioDefecto(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            'fecha_ingreso' => ['required', 'date'],
        ]);

        $horario = Horario::findOrFail($datos['horario_id']);

        return response()->json(
            $this->colaboradores->calendarioPorDefecto($horario, $datos['fecha_ingreso']),
        );
    }

    public function plantillaImportacion(Request $request): StreamedResponse
    {
        // Horarios es un catálogo global — la misma lista sirve para
        // cualquier empresa que descargue la plantilla. Empresas, en
        // cambio, se limita a las que el usuario realmente administra
        // (mismo criterio que empresasAutorizadas()), para que la columna
        // "empresa" nunca deje escribir a mano una empresa ajena.
        $nombresHorarios = Horario::where('activo', true)->orderBy('nombre')->pluck('nombre')->all();
        $nombresEmpresas = $this->empresasAutorizadas($request)->pluck('nombre_comercial')->sort()->values()->all();
        $libro = (new ColaboradorPlantillaGenerator)->generar($nombresHorarios, $nombresEmpresas);
        $escritor = new Xlsx($libro);

        return response()->streamDownload(function () use ($escritor) {
            $escritor->save('php://output');
        }, 'plantilla-colaboradores.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function previsualizarImportacion(ImportarColaboradoresRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->importador->previsualizar($this->empresasAutorizadas($request), $request->file('archivo')),
        ]);
    }

    public function importarColaboradores(ImportarColaboradoresRequest $request): JsonResponse
    {
        $resultado = $this->importador->importar($this->empresasAutorizadas($request), $request->file('archivo'));

        return response()->json([
            'message' => "{$resultado['creados']} colaboradores creados.",
            'data' => $resultado,
        ]);
    }

    /**
     * Un colaborador importado puede pertenecer a cualquier empresa que el
     * usuario realmente administre — no solo la empresa activa de su
     * sesión — para poder cargar en un solo archivo colaboradores de varias
     * empresas del mismo grupo. La empresa de cada fila la decide la
     * columna "empresa" del Excel (ver ImportarColaboradoresService), acá
     * solo se resuelve el universo de empresas autorizadas contra el cual
     * se valida ese nombre — mismo criterio que resolverEmpresaIds().
     *
     * @return Collection<int, Empresa>
     */
    private function empresasAutorizadas(Request $request): Collection
    {
        $usuario = $request->user('api');

        return $usuario->esAdministradorGlobal()
            ? Empresa::all()
            : $usuario->empresas()->get();
    }
}
