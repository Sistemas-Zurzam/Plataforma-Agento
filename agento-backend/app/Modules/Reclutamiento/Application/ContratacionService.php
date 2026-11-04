<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Application\EvaluarCompletitudColaborador;
use App\Modules\Personas\Application\RegistrarPeriodoPrueba;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Services\ColaboradorService;
use App\Modules\Reclutamiento\Domain\Contracts\IniciadorOnboarding;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contratación: integra Selección con Personas reutilizando
 * ColaboradorService::crear() (legajo, remuneración inicial, condición
 * laboral, horario y calendario) en lugar de escribir colaboradores
 * directamente.
 *
 * Concurrencia: la postulación y la plaza se bloquean (SELECT ... FOR UPDATE)
 * dentro de una transacción; el estado se revalida tras el bloqueo y
 * seleccion_plazas.postulacion_id es único. Un doble clic o dos contrataciones
 * simultáneas sobre la última plaza no pueden cubrirla dos veces.
 */
class ContratacionService
{
    public const RESOLUCION_VINCULAR = 'vincular_reingreso';

    public function __construct(
        private readonly ColaboradorService $colaboradores,
        private readonly EvaluarCompletitudColaborador $completitud,
        private readonly RegistrarPeriodoPrueba $periodosPrueba,
        private readonly IniciadorOnboarding $onboarding,
        private readonly ProcesoService $procesos,
        private readonly PostulacionService $postulaciones,
        private readonly RegistroActividad $actividad,
        private readonly EtiquetadoAutomatico $etiquetas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function contratar(AlcanceSeleccion $alcance, Postulacion $postulacion, array $datos): array
    {
        $empresa = Empresa::findOrFail($alcance->empresaId);

        return DB::transaction(function () use ($alcance, $postulacion, $datos, $empresa) {
            $bloqueada = Postulacion::whereKey($postulacion->id)->lockForUpdate()->firstOrFail();
            $estado = FlujoPostulacion::contratar($bloqueada->estado);

            $proceso = ProcesoSeleccion::whereKey($bloqueada->proceso_id)->lockForUpdate()->firstOrFail();
            if (in_array($proceso->estado, [ProcesoSeleccion::CANCELADO, ProcesoSeleccion::CERRADO, ProcesoSeleccion::BORRADOR], true)) {
                throw new TransicionInvalidaException("El proceso está {$proceso->estado}: no admite contrataciones.");
            }

            $plaza = Plaza::where('proceso_id', $proceso->id)
                ->where('estado', Plaza::DISPONIBLE)
                ->orderBy('numero')
                ->lockForUpdate()
                ->first();
            if (! $plaza) {
                throw ValidationException::withMessages(['plaza' => 'No quedan plazas disponibles en este proceso.']);
            }

            $candidato = $bloqueada->candidato()->firstOrFail();
            $tipoDocumento = $datos['tipo_documento'] ?? $candidato->tipo_documento;
            $numeroDocumento = $datos['numero_documento'] ?? $candidato->numero_documento;
            if (blank($tipoDocumento) || blank($numeroDocumento)) {
                throw ValidationException::withMessages(['numero_documento' => 'El candidato no tiene documento de identidad registrado.']);
            }

            [$colaborador, $esReingreso] = $this->resolverColaborador($empresa, $proceso, $candidato, $tipoDocumento, $numeroDocumento, $datos);

            $plaza->update([
                'estado' => Plaza::CUBIERTA,
                'postulacion_id' => $bloqueada->id,
                'colaborador_id' => $colaborador->id,
                'cubierta_at' => now(),
            ]);

            $estadoAnterior = $bloqueada->estado;
            $bloqueada->update(['estado' => $estado, 'contratada_at' => now(), 'colaborador_id' => $colaborador->id]);
            $this->postulaciones->registrarMovimiento($bloqueada, $bloqueada->etapa, $estadoAnterior, 'Contratación', $alcance->usuario->id);

            $periodoPrueba = null;
            if (! empty($datos['fecha_fin_prueba']) && ! $esReingreso) {
                $periodoPrueba = $this->periodosPrueba->registrar($colaborador, $datos['fecha_ingreso'], $datos['fecha_fin_prueba'], $alcance->usuario->id);
            }

            $faltantes = $this->completitud->evaluar($colaborador->load('documentos'));
            Tarea::create([
                'empresa_id' => $empresa->id,
                'tipo' => $esReingreso ? Tarea::REINGRESO : Tarea::COMPLETAR_FICHA,
                'titulo' => $esReingreso
                    ? "Gestionar reingreso de {$colaborador->nombres} {$colaborador->apellidos}"
                    : "Completar ficha de {$colaborador->nombres} {$colaborador->apellidos}",
                'detalle' => $esReingreso
                    ? 'Colaborador cesado vinculado desde Selección. Revisar en Personas el reingreso (no se modificó su ficha).'
                    : 'Ficha incompleta. Faltan: '.(collect($faltantes)->pluck('etiqueta')->implode(', ') ?: 'revisión general'),
                'permiso_responsable' => 'colaboradores.editar',
                'postulacion_id' => $bloqueada->id,
                'colaborador_id' => $colaborador->id,
                'estado' => 'pendiente',
            ]);

            $onboarding = $this->onboarding->iniciar($empresa->id, $colaborador->id, $bloqueada->id, $alcance->usuario->id);

            $this->procesos->verificarCobertura($proceso->refresh(), $alcance->usuario->id);
            $this->etiquetas->aplicar($empresa->id, $candidato->id, 'contratado');
            $this->actividad->registrar($empresa->id, $bloqueada, 'postulacion_contratada', $alcance->usuario->id, null, [
                'plaza' => $plaza->numero, 'colaborador_id' => $colaborador->id, 'reingreso' => $esReingreso,
            ]);

            return [
                'postulacion' => $bloqueada->refresh(),
                'plaza' => $plaza->refresh(),
                'colaborador' => ['id' => $colaborador->id, 'legajo' => $colaborador->legajo, 'nombre' => "{$colaborador->nombres} {$colaborador->apellidos}"],
                'ficha_incompleta' => $esReingreso ? null : $faltantes !== [],
                'faltantes' => $esReingreso ? [] : $faltantes,
                'reingreso' => $esReingreso,
                'periodo_prueba' => $periodoPrueba,
                'onboarding' => $onboarding,
                'proceso' => ['estado' => $proceso->refresh()->estado],
            ];
        });
    }

    /**
     * - Colaborador ACTIVO con el mismo documento en la empresa ⇒ bloqueo.
     * - Colaborador CESADO ⇒ Agento no tiene flujo de reingreso: se exige la
     *   resolución explícita "vincular_reingreso" (vincula sin duplicar ni
     *   modificar la ficha y genera tarea para Nóminas).
     * - Colaborador eliminado ⇒ debe restaurarse primero desde Personas.
     * - Sin coincidencias ⇒ se crea con ColaboradorService::crear().
     *
     * @return array{0: Colaborador, 1: bool}
     */
    private function resolverColaborador(Empresa $empresa, ProcesoSeleccion $proceso, $candidato, string $tipoDocumento, string $numeroDocumento, array $datos): array
    {
        $existente = Colaborador::withTrashed()
            ->where('empresa_id', $empresa->id)
            ->where('tipo_documento', $tipoDocumento)
            ->where('numero_documento', $numeroDocumento)
            ->lockForUpdate()
            ->first();

        if ($existente?->trashed()) {
            throw ValidationException::withMessages([
                'numero_documento' => 'Existe un colaborador eliminado con este documento. Restáuralo desde Personas antes de contratar.',
            ]);
        }

        if ($existente && $existente->activo && ! $existente->fecha_cese) {
            throw ValidationException::withMessages([
                'numero_documento' => "Ya existe un colaborador activo con este documento en la empresa (legajo {$existente->legajo}).",
            ]);
        }

        if ($existente) {
            if (($datos['resolucion_cesado'] ?? null) !== self::RESOLUCION_VINCULAR) {
                throw new HttpResponseException(response()->json([
                    'message' => 'La persona fue colaboradora de la empresa y está cesada. Confirma la vinculación para gestionar su reingreso sin duplicarla.',
                    'requiere_resolucion' => 'cesado',
                    'colaborador' => ['id' => $existente->id, 'legajo' => $existente->legajo, 'fecha_cese' => $existente->fecha_cese?->toDateString()],
                ], 409));
            }

            return [$existente, true];
        }

        $calendario = [];
        if (! empty($datos['horario_id'])) {
            $horario = Horario::findOrFail($datos['horario_id']);
            $calendario = collect($this->colaboradores->calendarioPorDefecto($horario, $datos['fecha_ingreso'])['dias'])
                ->where('editable', true)
                ->map(fn ($dia) => ['fecha' => $dia['fecha'], 'tipo' => $dia['tipo']])
                ->values()
                ->all();
        }

        $colaborador = $this->colaboradores->crear($empresa, [
            'sede_id' => (int) $datos['sede_id'],
            'area_id' => $proceso->area_id,
            'horario_id' => $datos['horario_id'] ?? null,
            'dias_descanso_rotativo_por_semana' => $datos['dias_descanso_rotativo_por_semana'] ?? null,
            'nombres' => $candidato->nombres,
            'apellido_paterno' => $candidato->apellido_paterno,
            'apellido_materno' => $candidato->apellido_materno,
            'tipo_documento' => $tipoDocumento,
            'numero_documento' => $numeroDocumento,
            'email' => $candidato->email,
            'celular_colaborador' => $datos['celular_colaborador'] ?? $candidato->celular,
            'celular_referencia' => $datos['celular_referencia'],
            'cargo' => $proceso->cargo,
            'tipo_contrato' => $datos['tipo_contrato'] ?? $proceso->tipo_contrato,
            'regimen_laboral' => $datos['regimen_laboral'] ?? null,
            'tipo_trabajador' => $datos['tipo_trabajador'],
            'categoria_trabajador' => $datos['categoria_trabajador'] ?? null,
            'es_trabajador_confianza' => (bool) ($datos['es_trabajador_confianza'] ?? false),
            // Mismos valores por defecto que el alta de Personas
            // (NuevoColaboradorModal); Nóminas puede ajustarlos al completar la ficha.
            'contabilizar_tardanzas' => true,
            'contabilizar_faltas' => true,
            'contabilizar_horas_extra' => true,
            'fecha_ingreso' => $datos['fecha_ingreso'],
            'fecha_fin_contrato' => $datos['fecha_fin_contrato'] ?? null,
            'modalidad_trabajo' => $datos['modalidad_trabajo'] ?? $proceso->modalidad,
            'salario' => $datos['salario'],
            'moneda_salario' => $datos['moneda_salario'] ?? $proceso->moneda,
            'periodicidad_pago' => $datos['periodicidad_pago'] ?? 'mensual',
            'calendario' => $calendario,
        ]);

        return [$colaborador, false];
    }
}
