<?php

namespace App\Modules\Asistencia\Application;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Nominas\Application\CiclosCerrados;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Primera etapa de la doble aprobación de horas extra: gerencia aprueba
 * (total o parcial) o rechaza desde el Portal de Empresa. Nunca valida para
 * Nómina — eso sigue siendo exclusivo de RR. HH. vía
 * AsistenciaDecisionService::resolverHoraExtra() (estado 'aprobado').
 *
 * Reglas:
 * - Solo sobre filas 'pendiente'; cualquier otro estado es un conflicto
 *   (decisión repetida, reintento o decisión simultánea).
 * - version = huella de la fila al abrirla. Si el reprocesamiento cambió el
 *   tiempo observado (o cualquier dato), el token deja de coincidir → 409.
 * - 0 < minutos_aprobados_gerencia ≤ minutos_observados (minutos enteros).
 * - Bloqueado en períodos de asistencia cerrados y ciclos cerrados/pagados.
 * - Rechazo de gerencia = 'rechazado' sin minutos aprobados vigentes.
 */
class DecidirHoraExtraGerencia
{
    public const ACCIONES = ['aprobar_total', 'aprobar_parcial', 'rechazar'];

    public function __construct(
        private readonly AsistenciaPeriodoService $periodos,
        private readonly CiclosCerrados $ciclos,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    public static function version(AsistenciaHoraExtra $horaExtra): string
    {
        return sha1(implode('|', [
            $horaExtra->id, $horaExtra->estado, (int) $horaExtra->minutos_observados,
            (int) $horaExtra->minutos_aprobados, (string) $horaExtra->minutos_aprobados_gerencia,
            $horaExtra->updated_at?->format('Y-m-d H:i:s.u'),
        ]));
    }

    public function ejecutar(int $horaExtraId, int $empresaId, string $accion, ?int $minutos, string $motivo, string $version, User $usuario): AsistenciaHoraExtra
    {
        return DB::transaction(function () use ($horaExtraId, $empresaId, $accion, $minutos, $motivo, $version, $usuario) {
            $horaExtra = AsistenciaHoraExtra::query()
                ->where('empresa_id', $empresaId)->whereKey($horaExtraId)
                ->lockForUpdate()->first();
            abort_unless($horaExtra, 404);

            if ($horaExtra->estado !== AsistenciaHoraExtra::ESTADO_PENDIENTE) {
                throw new ConflictHttpException('Esta hora extra ya fue decidida o validada. Recarga el listado para ver su estado actual.');
            }
            if (! hash_equals(self::version($horaExtra), $version)) {
                throw new ConflictHttpException('La hora extra cambió desde que la abriste (por ejemplo, por un reprocesamiento de asistencia). Recarga para ver el tiempo actualizado.');
            }

            $fecha = $horaExtra->fecha->toDateString();
            $this->periodos->asegurarRangoEditable($empresaId, $fecha, $fecha);
            $this->ciclos->asegurarAbierto($empresaId, $fecha, $fecha);

            $observados = (int) $horaExtra->minutos_observados;
            $aprobados = match ($accion) {
                'aprobar_total' => $observados,
                'aprobar_parcial' => (int) $minutos,
                default => 0,
            };
            if ($accion === 'aprobar_parcial' && ($aprobados <= 0 || $aprobados > $observados)) {
                throw ValidationException::withMessages([
                    'minutos' => ["La aprobación parcial debe ser mayor que 0 y no superar {$observados} minutos observados."],
                ]);
            }

            $antes = $horaExtra->toArray();
            $esAprobacion = $accion !== 'rechazar';
            $horaExtra->update([
                'estado' => $esAprobacion ? AsistenciaHoraExtra::ESTADO_APROBADO_GERENCIA : AsistenciaHoraExtra::ESTADO_RECHAZADO,
                'minutos_aprobados_gerencia' => $esAprobacion ? $aprobados : null,
                'motivo_gerencia' => $motivo,
                'gerencia_resuelto_por' => $usuario->id,
                'gerencia_resuelto_at' => now(),
                // El rechazo de gerencia es final y no deja minutos vigentes.
                'minutos_aprobados' => 0,
                'motivo' => $esAprobacion ? $horaExtra->motivo : $motivo,
                'resuelto_por' => $esAprobacion ? $horaExtra->resuelto_por : $usuario->id,
                'resuelto_at' => $esAprobacion ? $horaExtra->resuelto_at : now(),
            ]);

            $this->auditoria->registrar(
                $empresaId, $usuario->id, 'hora_extra_gerencia_'.$accion, $horaExtra, $motivo,
                $antes, $horaExtra->fresh()->toArray(),
            );

            return $horaExtra->fresh(['colaborador.area', 'gerenciaResueltoPor:id,name', 'resueltoPor:id,name']);
        });
    }
}
