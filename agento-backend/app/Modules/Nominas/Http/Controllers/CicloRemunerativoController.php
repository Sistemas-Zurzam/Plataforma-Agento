<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Http\Requests\TransicionarAsistenciaRequest;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use App\Modules\Configuracion\Http\Resources\EmpresaCuentaBancariaResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Nominas\Application\AfpNet\AfpNetExportService;
use App\Modules\Nominas\Application\AfpNet\AfpNetValidator;
use App\Modules\Nominas\Application\BbvaNetCash\BbvaNetCashExportService;
use App\Modules\Nominas\Application\BbvaNetCash\BbvaNetCashValidator;
use App\Modules\Nominas\Application\Plame\PlameExportService;
use App\Modules\Nominas\Application\Plame\PlameValidator;
use App\Modules\Nominas\Application\TelecreditoBcp\TelecreditoBcpExportService;
use App\Modules\Nominas\Application\TelecreditoBcp\TelecreditoBcpValidator;
use App\Modules\Nominas\Domain\AfpNet\AfpNetExportResultado;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportResultado;
use App\Modules\Nominas\Domain\Plame\PlameExportResultado;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpExportResultado;
use App\Modules\Nominas\Http\Resources\CicloRemunerativoResource;
use App\Modules\Nominas\Http\Resources\ColaboradorConceptoPeriodoResource;
use App\Modules\Nominas\Http\Resources\IncidenciaPendienteResource;
use App\Modules\Nominas\Infrastructure\Plame\Export\PlameZipBuilder;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CicloRemunerativoController extends Controller
{
    public function __construct(
        private readonly CicloRemunerativoService $ciclos,
        private readonly BoletaService $boletas,
        private readonly PlameValidator $plameValidator,
        private readonly PlameExportService $plameExportService,
        private readonly AfpNetValidator $afpNetValidator,
        private readonly AfpNetExportService $afpNetExportService,
        private readonly TelecreditoBcpValidator $telecreditoBcpValidator,
        private readonly TelecreditoBcpExportService $telecreditoBcpExportService,
        private readonly BbvaNetCashValidator $bbvaNetCashValidator,
        private readonly BbvaNetCashExportService $bbvaNetCashExportService,
        private readonly AsistenciaDecisionService $asistenciaDecisiones,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CicloRemunerativoResource::collection(
            $this->ciclos->listar($this->empresaIdsAutorizadas($request), max(1, min((int) $request->input('per_page', 15), 50))),
        );
    }

    /**
     * El selector de "Planilla mensual" del frontend siempre lista los
     * ciclos de TODAS las empresas que el usuario realmente administra
     * (empresa_usuario) — nunca solo la empresa activa —, agrupados por
     * empresa. Un administrador global ve literalmente todas las del
     * sistema (ver User::esAdministradorGlobal). Mismo criterio que
     * ColaboradorController::resolverEmpresaIds().
     *
     * @return array<int, int>
     */
    private function empresaIdsAutorizadas(Request $request): array
    {
        $usuario = $request->user('api');

        return $usuario->esAdministradorGlobal()
            ? Empresa::pluck('id')->all()
            : $usuario->empresas()->pluck('empresas.id')->all();
    }

    /**
     * Cada acción sobre un ciclo puntual usa la empresa DEL CICLO (no la
     * empresa activa de la sesión) — el usuario puede operar sobre un ciclo
     * de cualquier empresa que realmente administre, sin necesidad de
     * cambiar su empresa activa primero. empresa_id del ciclo nunca se
     * confía como autorización por sí solo: siempre se valida contra
     * User::tieneAccesoA().
     */
    private function empresaAutorizadaDelCiclo(Request $request, CicloRemunerativo $ciclo): Empresa
    {
        $empresa = $ciclo->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a la empresa de este ciclo remunerativo.');

        return $empresa;
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

    public function actualizar(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_corte_asistencia' => ['required', 'date'],
            'fecha_pago' => ['required', 'date', 'after_or_equal:fecha_fin'],
        ]);

        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $ciclo = $this->ciclos->actualizar($empresa, $ciclo, $datos);

        return new CicloRemunerativoResource($ciclo);
    }

    public function eliminar(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $this->ciclos->eliminar($empresa, $ciclo);

        return response()->json(['message' => 'Ciclo remunerativo eliminado.']);
    }

    public function calcular(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $motivo = $request->input('motivo_recalculo');

        $this->boletas->iniciarCalculoAsync($empresa, $ciclo, $request->user('api')->id, $motivo);

        return response()->json([
            'message' => 'El cálculo se está procesando en segundo plano.',
            'calculo_estado' => 'en_proceso',
        ], 202);
    }

    public function estadoCalculo(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json([
            'calculo_estado' => $ciclo->calculo_estado,
            'calculo_iniciado_at' => $ciclo->calculo_iniciado_at?->toDateTimeString(),
            'calculo_finalizado_at' => $ciclo->calculo_finalizado_at?->toDateTimeString(),
            'calculo_resultado' => $ciclo->calculo_resultado,
        ]);
    }

    public function incidenciasPendientesCierre(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return IncidenciaPendienteResource::collection($this->ciclos->incidenciasPendientesCierre($empresa, $ciclo));
    }

    /**
     * Permite Aprobar/Rechazar una incidencia directamente desde el modal de
     * bloqueo de Nóminas (cerrar ciclo / aprobar boleta), sin navegar hasta
     * Gestión de Asistencias. Reutiliza AsistenciaDecisionService::
     * resolverIncidencia() tal cual — misma lógica, misma auditoría — pero
     * autoriza contra la empresa DEL CICLO (empresaAutorizadaDelCiclo), no
     * contra la empresa activa de la sesión: un admin que gestiona varias
     * empresas puede estar viendo el ciclo de una que no tiene activa en
     * este momento. Por el mismo motivo se busca la incidencia sin el scope
     * automático de empresa (igual que IncidenciasPendientesNominaService) —
     * la pertenencia real se valida dentro de resolverIncidencia() contra la
     * empresa ya autorizada del ciclo.
     */
    public function resolverIncidenciaPendienteCierre(TransicionarAsistenciaRequest $request, CicloRemunerativo $ciclo, int $incidencia): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $incidenciaModelo = AsistenciaIncidencia::withoutGlobalScope(EmpresaScope::class)->findOrFail($incidencia);

        $this->asistenciaDecisiones->resolverIncidencia($empresa, $incidenciaModelo, $request->validated(), $request->user('api'));

        return response()->json(['message' => 'Incidencia resuelta.']);
    }

    public function cerrar(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return new CicloRemunerativoResource($this->ciclos->cerrar($empresa, $ciclo));
    }

    public function reabrir(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return new CicloRemunerativoResource($this->ciclos->reabrir($empresa, $ciclo));
    }

    public function marcarPagado(Request $request, CicloRemunerativo $ciclo): CicloRemunerativoResource
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return new CicloRemunerativoResource($this->ciclos->marcarPagado($empresa, $ciclo));
    }

    public function listarConceptos(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador): AnonymousResourceCollection
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return ColaboradorConceptoPeriodoResource::collection(
            $this->ciclos->listarConceptos($empresa, $ciclo, $colaborador),
        );
    }

    public function registrarConcepto(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador): ColaboradorConceptoPeriodoResource
    {
        // BONIFICACION/BONO_NO_REMUNERATIVO son demasiado genéricos para
        // Tabla 22 — si el concepto elegido es uno de esos, exige indicar
        // qué definición concreta (ver concepto_definiciones_plame)
        // corresponde, para que el snapshot de la boleta conserve el
        // código PLAME correcto en vez de uno genérico.
        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);

        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $requiereDefinicion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $item = $this->ciclos->registrarConcepto($empresa, $ciclo, $colaborador, $datos, $request->user('api')->id);

        return new ColaboradorConceptoPeriodoResource($item->load(['concepto', 'conceptoDefinicion']));
    }

    public function actualizarConcepto(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador, ColaboradorConceptoPeriodo $conceptoPeriodo): ColaboradorConceptoPeriodoResource
    {
        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);

        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $requiereDefinicion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $item = $this->ciclos->actualizarConcepto($empresa, $ciclo, $colaborador, $conceptoPeriodo, $datos);

        return new ColaboradorConceptoPeriodoResource($item->load(['concepto', 'conceptoDefinicion']));
    }

    public function eliminarConcepto(Request $request, CicloRemunerativo $ciclo, Colaborador $colaborador, ColaboradorConceptoPeriodo $conceptoPeriodo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);
        $this->ciclos->eliminarConcepto($empresa, $ciclo, $colaborador, $conceptoPeriodo);

        return response()->json(['message' => 'Concepto eliminado.']);
    }

    /**
     * Validación de preparación PLAME (Sección 48) — NO genera ningún
     * archivo, solo reporta si los datos que participan en este ciclo
     * alcanzan para construir .jor/.snl/.rem/.ps4/.4ta. Controller delgado
     * (Sección 53): toda la lógica vive en PlameValidator, reutiliza la
     * misma autorización de tenant que el resto de acciones de ciclo.
     */
    public function validarPlame(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json($this->plameValidator->validar($ciclo));
    }

    public function exportarPlamePlanilla(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        return $this->responderExportacion($request, $ciclo, 'planilla', fn () => $this->plameExportService->exportarPlanilla($ciclo));
    }

    public function exportarPlameRh(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        return $this->responderExportacion($request, $ciclo, 'rh', fn () => $this->plameExportService->exportarRh($ciclo));
    }

    public function exportarPlameCompleto(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        return $this->responderExportacion($request, $ciclo, 'completo', fn () => $this->plameExportService->exportarCompleto($ciclo));
    }

    /**
     * Punto único de respuesta para las 3 operaciones de exportación
     * (Sección 52): si PlameExportService no pudo generar, devuelve un
     * error de negocio estructurado (422), nunca un 500. Si generó 0
     * archivos aplicables (ej. sin RH en el período), también responde
     * JSON informativo en vez de un ZIP vacío. Solo cuando hay al menos un
     * archivo se arma el ZIP y se descarga.
     */
    private function responderExportacion(Request $request, CicloRemunerativo $ciclo, string $tipo, callable $generar): JsonResponse|BinaryFileResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        /** @var PlameExportResultado $resultado */
        $resultado = $generar();

        if (! $resultado->listo || $resultado->archivos === []) {
            return response()->json([
                'message' => $resultado->mensaje,
                'codigo' => $resultado->codigo,
                'validacion' => $resultado->validacion,
            ], $resultado->listo ? 200 : 422);
        }

        // Auditoría (Sección 54) — solo metadatos, nunca el contenido de
        // los .txt ni el detalle completo de documentos de cada trabajador.
        Log::info('plame.export', [
            'usuario_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'periodo' => ['inicio' => $ciclo->fecha_inicio->toDateString(), 'fin' => $ciclo->fecha_fin->toDateString()],
            'tipo_exportacion' => $tipo,
            'archivos' => collect($resultado->archivos)->pluck('nombre')->all(),
        ]);

        $rutaZip = PlameZipBuilder::construir($resultado->archivos);
        $nombreZip = sprintf('PLAME_%s_%s.zip', Str::slug($empresa->nombre_comercial), $ciclo->fecha_inicio->format('Y_m'));

        return response()->download($rutaZip, $nombreZip, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * Validación AFPnet — completamente independiente de PLAME (Sección 3
     * del encargo AFPnet: no comparte Validator, Service ni datos).
     */
    public function validarAfpNet(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $this->empresaAutorizadaDelCiclo($request, $ciclo);

        return response()->json($this->afpNetValidator->validar($ciclo));
    }

    public function exportarAfpNetExcel(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        return $this->responderExportacionAfpNet($request, $ciclo, 'excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', fn () => $this->afpNetExportService->exportarExcel($ciclo));
    }

    public function exportarAfpNetTxt(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        return $this->responderExportacionAfpNet($request, $ciclo, 'txt', 'text/plain', fn () => $this->afpNetExportService->exportarTxt($ciclo));
    }

    /**
     * Mismo criterio que responderExportacion() (PLAME): error de negocio
     * estructurado en 422, nunca 500. AFPnet genera UN solo archivo por
     * formato (no hay ZIP, Sección 33/34) — se descarga directo.
     */
    private function responderExportacionAfpNet(Request $request, CicloRemunerativo $ciclo, string $formato, string $contentType, callable $generar): JsonResponse|BinaryFileResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        /** @var AfpNetExportResultado $resultado */
        $resultado = $generar();

        if (! $resultado->listo || $resultado->archivo === null) {
            return response()->json([
                'message' => $resultado->mensaje,
                'codigo' => $resultado->codigo,
                'validacion' => $resultado->validacion,
            ], $resultado->listo ? 200 : 422);
        }

        // Auditoría (Sección 45) — solo metadatos, nunca CUSPP/DNI masivos
        // ni remuneraciones individuales ni el contenido del archivo.
        Log::info('afpnet.export', [
            'usuario_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'periodo' => ['inicio' => $ciclo->fecha_inicio->toDateString(), 'fin' => $ciclo->fecha_fin->toDateString()],
            'formato' => $formato,
            'trabajadores' => $resultado->validacion['resumen']['trabajadores'] ?? null,
        ]);

        $ruta = tempnam(sys_get_temp_dir(), 'afpnet_');
        file_put_contents($ruta, $resultado->archivo['contenido']);

        return response()->download($ruta, $resultado->archivo['nombre'], ['Content-Type' => $contentType])->deleteFileAfterSend(true);
    }

    /**
     * Validación Telecrédito BCP — completamente independiente de PLAME/
     * AFPnet (preparación, sin exportador todavía). `cuenta_cargo_id` se
     * resuelve SIEMPRE contra la empresa del ciclo (Sección 42 del
     * encargo): nunca se confía en que la cuenta enviada por el frontend
     * pertenece a la empresa correcta.
     */
    public function validarTelecreditoBcp(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        $datos = $request->validate([
            'cuenta_cargo_id' => ['required', 'integer'],
            'fecha_proceso' => ['required', 'date'],
            'subtipo' => ['required', 'string', 'size:1'],
            'boleta_ids' => ['sometimes', 'array', 'min:1', 'max:5000'],
            'boleta_ids.*' => ['required', 'integer', 'distinct', Rule::exists('boletas', 'id')->where('ciclo_id', $ciclo->id)],
        ]);

        $cuentaCargo = EmpresaCuentaBancaria::where('empresa_id', $empresa->id)->findOrFail($datos['cuenta_cargo_id']);

        return response()->json(
            $this->telecreditoBcpValidator->validar($ciclo, $cuentaCargo, $datos['fecha_proceso'], $datos['subtipo'], $datos['boleta_ids'] ?? []),
        );
    }

    /**
     * Exportación Telecrédito BCP — gate MÁS restrictivo que la validación
     * (Sección 35 del encargo): la ruta exige `nominas.telecredito_exportar`,
     * no `nominas.ver`, porque este archivo puede terminar en movimiento
     * real de dinero una vez cargado al banco. Nunca cambia
     * boleta.estado/ciclo.estado/referencia_pago (Sección 42): descargar
     * el TXT no significa que BCP ya pagó.
     */
    public function exportarTelecreditoBcp(Request $request, CicloRemunerativo $ciclo): JsonResponse|BinaryFileResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        $datos = $request->validate([
            'cuenta_cargo_id' => ['required', 'integer'],
            'fecha_proceso' => ['required', 'date'],
            'subtipo' => ['required', 'string', 'size:1'],
            'boleta_ids' => ['sometimes', 'array', 'min:1', 'max:5000'],
            'boleta_ids.*' => ['required', 'integer', 'distinct', Rule::exists('boletas', 'id')->where('ciclo_id', $ciclo->id)],
        ]);

        $cuentaCargo = EmpresaCuentaBancaria::where('empresa_id', $empresa->id)->findOrFail($datos['cuenta_cargo_id']);

        /** @var TelecreditoBcpExportResultado $resultado */
        $resultado = $this->telecreditoBcpExportService->exportar($ciclo, $cuentaCargo, $datos['fecha_proceso'], $datos['subtipo'], $datos['boleta_ids'] ?? []);

        if (! $resultado->listo || $resultado->archivo === null) {
            return response()->json([
                'message' => $resultado->mensaje,
                'codigo' => $resultado->codigo,
                'validacion' => $resultado->validacion,
            ], $resultado->listo ? 200 : 422);
        }

        // Auditoría (Sección 43) — cuenta de cargo por ID, NUNCA su número;
        // nunca cuentas/CCI/DNI de trabajadores ni el contenido del TXT.
        Log::info('telecredito_bcp.export', [
            'usuario_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'periodo' => ['inicio' => $ciclo->fecha_inicio->toDateString(), 'fin' => $ciclo->fecha_fin->toDateString()],
            'cuenta_cargo_id' => $cuentaCargo->id,
            'fecha_proceso' => $datos['fecha_proceso'],
            'subtipo' => $datos['subtipo'],
            'seleccion_boletas' => $datos['boleta_ids'] ?? null,
            'abonos' => $resultado->validacion['abonos'] ?? null,
            'monto_total' => $resultado->validacion['monto_total'] ?? null,
        ]);

        $ruta = tempnam(sys_get_temp_dir(), 'telecredito_');
        file_put_contents($ruta, $resultado->archivo['contenido']);

        return response()->download($ruta, $resultado->archivo['nombre'], ['Content-Type' => 'text/plain'])->deleteFileAfterSend(true);
    }

    /**
     * Validación BBVA Net Cash — completamente independiente de
     * Telecrédito BCP/PLAME/AFPnet. A diferencia de Telecrédito, el
     * frontend NUNCA envía `cuenta_cargo_id`: la cuenta de cargo BBVA se
     * resuelve siempre desde la empresa del ciclo (punto 20 del encargo).
     */
    public function validarBbvaNetCash(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        $datos = $request->validate([
            'subtipo' => ['required', 'string', Rule::in(['4', '5'])],
            'boleta_ids' => ['sometimes', 'array', 'min:1', 'max:5000'],
            'boleta_ids.*' => ['required', 'integer', 'distinct', Rule::exists('boletas', 'id')->where('ciclo_id', $ciclo->id)],
        ]);

        $cuentaCargo = $this->resolverCuentaCargoBbva($empresa);

        return response()->json([
            ...$this->bbvaNetCashValidator->validar($ciclo, $cuentaCargo, $datos['subtipo'], $datos['boleta_ids'] ?? []),
            'cuenta_cargo' => new EmpresaCuentaBancariaResource($cuentaCargo),
        ]);
    }

    /**
     * Exportación BBVA Net Cash — gate MÁS restrictivo que la validación
     * (mismo criterio que Telecrédito): la ruta exige
     * `nominas.bbva_netcash_exportar`, no `nominas.ver`, porque este
     * archivo puede terminar en movimiento real de dinero una vez cargado
     * al banco. Nunca cambia boleta.estado/ciclo.estado: descargar el TXT
     * no significa que BBVA ya pagó.
     */
    public function exportarBbvaNetCash(Request $request, CicloRemunerativo $ciclo): JsonResponse|StreamedResponse
    {
        $empresa = $this->empresaAutorizadaDelCiclo($request, $ciclo);

        $datos = $request->validate([
            'subtipo' => ['required', 'string', Rule::in(['4', '5'])],
            'boleta_ids' => ['sometimes', 'array', 'min:1', 'max:5000'],
            'boleta_ids.*' => ['required', 'integer', 'distinct', Rule::exists('boletas', 'id')->where('ciclo_id', $ciclo->id)],
        ]);

        $cuentaCargo = $this->resolverCuentaCargoBbva($empresa);

        /** @var BbvaNetCashExportResultado $resultado */
        $resultado = $this->bbvaNetCashExportService->exportar($ciclo, $cuentaCargo, $datos['subtipo'], $datos['boleta_ids'] ?? []);

        if (! $resultado->listo || $resultado->archivo === null) {
            return response()->json([
                'message' => $resultado->mensaje,
                'codigo' => $resultado->codigo,
                'validacion' => $resultado->validacion,
            ], $resultado->listo ? 200 : 422);
        }

        // Auditoría — cuenta de cargo por ID, NUNCA su número; nunca
        // cuentas/CCI/DNI de trabajadores ni el contenido del TXT.
        Log::info('bbva_netcash.export', [
            'usuario_id' => $request->user('api')->id,
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'periodo' => ['inicio' => $ciclo->fecha_inicio->toDateString(), 'fin' => $ciclo->fecha_fin->toDateString()],
            'cuenta_cargo_id' => $cuentaCargo->id,
            'subtipo' => $datos['subtipo'],
            'seleccion_boletas' => $datos['boleta_ids'] ?? null,
            'abonos' => $resultado->validacion['abonos'] ?? null,
            'monto_total' => $resultado->validacion['monto_total'] ?? null,
        ]);

        // streamDownload() en vez de tempnam()+download() (patrón que sí
        // usa Telecrédito): el contenido ya está completo en memoria, no
        // hace falta pasar por disco. Evita además que un `tempnam()` que
        // termine creando el archivo en una ruta distinta a la pedida
        // (warning real de PHP en este entorno, que Laravel escala a
        // excepción) tumbe la descarga.
        return response()->streamDownload(
            fn () => print($resultado->archivo['contenido']),
            $resultado->archivo['nombre'],
            ['Content-Type' => 'text/plain'],
        );
    }

    /**
     * Resuelve la cuenta de cargo BBVA de la empresa (punto 20/21 del
     * encargo): reutiliza `empresa_cuentas_bancarias` (misma tabla que ya
     * usa Telecrédito) filtrando por banco=bbva + uso=haberes + activo.
     * Si hay más de una activa, gana la marcada `es_predeterminada`; si
     * sigue siendo ambigua, o no existe ninguna, error claro — nunca
     * adivina cuál usar.
     */
    private function resolverCuentaCargoBbva(Empresa $empresa): EmpresaCuentaBancaria
    {
        $cuentas = EmpresaCuentaBancaria::where('empresa_id', $empresa->id)
            ->where('uso', 'haberes')
            ->where('activo', true)
            ->whereHas('banco', fn ($query) => $query->where('codigo', 'bbva'))
            ->get();

        $cuenta = $cuentas->firstWhere('es_predeterminada', true)
            ?? ($cuentas->count() === 1 ? $cuentas->first() : null);

        abort_if($cuenta === null, 422, 'La empresa no tiene una cuenta BBVA Net Cash configurada para pago de haberes.');

        return $cuenta;
    }
}
