<?php

namespace App\Modules\PortalCliente\Application;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Asistencia\Services\PlanificacionRotativaService;
use App\Modules\Nominas\Application\CiclosCerrados;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guardado de planificación de rotativos desde el portal. Reutiliza
 * PlanificacionRotativaService::planificarDia() (misma validación de
 * trabajo en descanso, auditoría y reprocesamiento condicionado) con
 * origen 'portal', y agrega las reglas del portal:
 *
 * - Todo el lote se valida ANTES de escribir; si hay un solo conflicto no
 *   se aplica nada y se devuelven todos los conflictos por colaborador y
 *   fecha (409). No existen cambios parciales silenciosos.
 * - Concurrencia: cada cambio declara el tipo que el usuario vio
 *   (tipo_anterior). Si otro usuario o un proceso lo cambió, es conflicto.
 * - Feriados: de solo lectura (FeriadosPeru prevalece en la jornada).
 * - Períodos de asistencia cerrados y ciclos cerrados/pagados: bloqueados.
 * - Días con ajuste manual de RR. HH.: no se modifican desde el portal (se
 *   derivan a RR. HH.) para no reprocesar ni pisar una corrección manual.
 */
class PlanificarEquipoPortal
{
    public const TIPOS = ['laborable_presencial', 'home_office', 'descanso'];

    public function __construct(
        private readonly PlanificacionRotativaService $planificacion,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly CiclosCerrados $ciclos,
    ) {}

    /**
     * @param  array<int, array{colaborador_id: int, fecha: string, tipo: ?string, tipo_anterior: ?string}>  $cambios
     * @return array{aplicados: int}
     */
    public function ejecutar(AlcancePortal $alcance, array $cambios): array
    {
        $empresaId = $alcance->empresa->id;

        $colaboradorIds = array_values(array_unique(array_map(fn ($c) => (int) $c['colaborador_id'], $cambios)));
        $colaboradores = $alcance->colaboradores()->whereIn('colaboradores.id', $colaboradorIds)->get()->keyBy('id');
        // Un id fuera del alcance invalida todo el lote sin revelar nada.
        abort_if($colaboradores->count() !== count($colaboradorIds), 404, 'Uno o más colaboradores no pertenecen a tu equipo.');

        return DB::transaction(function () use ($alcance, $cambios, $colaboradores, $empresaId) {
            // Bloqueo de las fichas involucradas: dos guardados simultáneos
            // del mismo equipo se serializan y el segundo ve el estado real.
            Colaborador::query()->whereIn('id', $colaboradores->keys())->lockForUpdate()->get();

            // whereDate por rango (no whereIn sobre la columna date): mismo
            // resultado en MySQL y SQLite sin depender del formato guardado.
            $fechas = array_column($cambios, 'fecha');
            [$desde, $hasta] = [min($fechas), max($fechas)];

            $actuales = ColaboradorCalendarioDia::query()
                ->whereIn('colaborador_id', $colaboradores->keys())
                ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)
                ->get()
                ->keyBy(fn ($dia) => $dia->colaborador_id.'|'.$dia->fecha->toDateString());

            $ajustados = AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresaId)
                ->whereIn('colaborador_id', $colaboradores->keys())
                ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)
                ->whereNotNull('ajustado_manualmente_at')
                ->get(['colaborador_id', 'fecha'])
                ->mapWithKeys(fn ($r) => [$r->colaborador_id.'|'.$r->fecha->toDateString() => true]);

            $conflictos = [];
            foreach ($cambios as $cambio) {
                $colaborador = $colaboradores->get((int) $cambio['colaborador_id']);
                $fecha = $cambio['fecha'];
                $clave = $colaborador->id.'|'.$fecha;
                $motivo = $this->conflicto($colaborador, $fecha, $cambio, $actuales->get($clave)?->tipo, isset($ajustados[$clave]), $empresaId);
                if ($motivo) {
                    $conflictos[] = $this->filaConflicto($colaborador, $fecha, $motivo);
                }
            }
            if ($conflictos) {
                $this->rechazar($conflictos);
            }

            $aplicados = 0;
            foreach ($cambios as $cambio) {
                $colaborador = $colaboradores->get((int) $cambio['colaborador_id']);
                try {
                    $this->planificacion->planificarDia(
                        $alcance->empresa, $colaborador, $cambio['fecha'], $cambio['tipo'],
                        $alcance->usuario, ColaboradorCalendarioDia::ORIGEN_PORTAL,
                    );
                    $aplicados++;
                } catch (ValidationException $e) {
                    $conflictos[] = $this->filaConflicto($colaborador, $cambio['fecha'], collect($e->errors())->flatten()->first());
                }
            }
            // Una regla de dominio falló durante la escritura: se revierte
            // TODO el lote (la excepción aborta la transacción).
            if ($conflictos) {
                $this->rechazar($conflictos);
            }

            return ['aplicados' => $aplicados];
        });
    }

    /**
     * @param  array<string, mixed>  $cambio
     */
    private function conflicto(Colaborador $colaborador, string $fecha, array $cambio, ?string $tipoActual, bool $ajustado, int $empresaId): ?string
    {
        $dia = Carbon::parse($fecha);
        if (FeriadosPeru::esFeriado($fecha)) {
            return 'Es feriado: se planifica automáticamente y no puede modificarse.';
        }
        if ($colaborador->fecha_ingreso && $dia->lt($colaborador->fecha_ingreso)) {
            return 'La fecha es anterior al ingreso del colaborador.';
        }
        if (($cambio['tipo_anterior'] ?? null) !== $tipoActual) {
            return 'La planificación de este día cambió desde que la abriste. Recarga la semana.';
        }
        if ($ajustado) {
            return 'El día tiene un ajuste manual de RR. HH.; solicita el cambio a RR. HH.';
        }
        try {
            $this->periodos->asegurarRangoEditable($empresaId, $fecha, $fecha);
        } catch (ValidationException) {
            return 'El período de asistencia de esta fecha está cerrado.';
        }
        if ($this->ciclos->cubreRango($empresaId, $fecha, $fecha)) {
            return 'La fecha pertenece a un ciclo remunerativo cerrado o pagado.';
        }

        return null;
    }

    /**
     * @return array{colaborador_id: int, colaborador: string, fecha: string, motivo: string}
     */
    private function filaConflicto(Colaborador $colaborador, string $fecha, string $motivo): array
    {
        return [
            'colaborador_id' => $colaborador->id,
            'colaborador' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'fecha' => $fecha,
            'motivo' => $motivo,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $conflictos
     */
    private function rechazar(array $conflictos): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'No se guardó ningún cambio: hay '.count($conflictos).' conflicto(s). Corrígelos y vuelve a intentar.',
            'conflictos' => $conflictos,
        ], 409));
    }
}
