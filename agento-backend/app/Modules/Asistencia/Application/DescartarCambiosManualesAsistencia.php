<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Reprocesar todo": devuelve un rango de asistencia al estado que produce
 * el motor a partir de las marcaciones crudas, descartando las decisiones
 * manuales de RR.HH. Solo prepara los datos; el reproceso día a día lo hace
 * ReprocesarAsistenciaRango a continuación.
 *
 * Nunca borra marcaciones crudas ni permisos aprobados: las marcaciones
 * manuales se anulan (quedan como evidencia) y las resoluciones vuelven a
 * pendiente conservando el mismo registro. Las alertas de ajuste post-pago
 * son avisos de Nómina, no decisiones de asistencia, y no se tocan.
 */
class DescartarCambiosManualesAsistencia
{
    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    /**
     * @param  array<int, int>  $colaboradorIds
     * @return array{
     *     marcaciones_anuladas: int, salidas_desvinculadas: int, estados_forzados: int, dias_ajustados: int,
     *     calendario_revertido: int, incidencias_reabiertas: int, horas_extra_reabiertas: int,
     *     fechas_adicionales: array<int, array{colaborador_id: int, fecha: string}>
     * }
     */
    public function ejecutar(Empresa $empresa, int $usuarioId, array $colaboradorIds, string $fechaDesde, string $fechaHasta, string $motivo): array
    {
        return DB::transaction(function () use ($empresa, $usuarioId, $colaboradorIds, $fechaDesde, $fechaHasta, $motivo) {
            $marcacionesAnuladas = AsistenciaMarcacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->where('origen', 'manual_rrhh')
                ->whereNull('anulada_at')
                ->whereBetween('marcado_at', [Carbon::parse($fechaDesde)->startOfDay(), Carbon::parse($fechaHasta)->endOfDay()])
                ->update(['anulada_at' => now(), 'anulada_por' => $usuarioId]);

            // La salida vinculada pertenece a la madrugada del día siguiente:
            // al desvincularla ese día también debe reprocesarse, aunque
            // quede fuera del rango pedido.
            $fechasAdicionales = [];
            $vinculadas = AsistenciaMarcacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereNotNull('datos_origen->vinculada_a_fecha')
                ->where('datos_origen->vinculada_a_fecha', '>=', $fechaDesde)
                ->where('datos_origen->vinculada_a_fecha', '<=', $fechaHasta)
                ->get();
            foreach ($vinculadas as $marcacion) {
                $origen = $marcacion->datos_origen;
                unset($origen['vinculada_a_fecha'], $origen['vinculada_por'], $origen['vinculada_motivo']);
                $marcacion->update(['datos_origen' => $origen === [] ? null : $origen]);

                $fechaMarca = $marcacion->marcado_at->toDateString();
                if ($fechaMarca > $fechaHasta) {
                    $fechasAdicionales[] = ['colaborador_id' => $marcacion->colaborador_id, 'fecha' => $fechaMarca];
                }
            }

            $estadosForzados = AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->whereNotNull('estado_forzado')
                ->update(['estado_forzado' => null]);
            // Sin la marca de ajuste manual el día deja de estar protegido y
            // el reproceso siguiente lo recalcula desde las marcaciones.
            $diasAjustados = AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->whereNotNull('ajustado_manualmente_at')
                ->update(['ajustado_manualmente_at' => null, 'ajustado_por' => null, 'motivo_ajuste' => null]);

            // Planificación decidida al resolver incidencias (clasificar un día
            // rotativo, corregir un descanso, otorgar un sustitutorio): se
            // elimina y el día vuelve a resolverse desde el horario (o queda
            // sin clasificar si es rotativo). Del horario excepcional solo se
            // retira el horario: el tipo de día pudo venir de una
            // planificación previa que editarDia() sobrescribió.
            $calendarioEliminado = ColaboradorCalendarioDia::query()
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->whereIn('origen', [ColaboradorCalendarioDia::ORIGEN_INCIDENCIA, ColaboradorCalendarioDia::ORIGEN_DESCANSO_SUSTITUTORIO])
                ->delete();
            $horariosExcepcionales = ColaboradorCalendarioDia::query()
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->whereNotNull('horario_excepcional_id')
                ->update(['horario_excepcional_id' => null]);

            $incidenciasReabiertas = AsistenciaIncidencia::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->where('estado', '!=', AsistenciaIncidencia::ESTADO_PENDIENTE)
                ->where('tipo', '!=', AsistenciaIncidencia::TIPO_AJUSTE_POST_PAGO_PENDIENTE)
                ->update(['estado' => AsistenciaIncidencia::ESTADO_PENDIENTE, 'motivo_resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);

            // Solo las resueltas por una persona: el feriado trabajado se
            // aprueba solo (resuelto_por null) y el motor lo recalcula igual.
            $horasExtraReabiertas = AsistenciaHoraExtra::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('colaborador_id', $colaboradorIds)
                ->whereDate('fecha', '>=', $fechaDesde)->whereDate('fecha', '<=', $fechaHasta)
                ->where('estado', '!=', AsistenciaHoraExtra::ESTADO_PENDIENTE)
                ->whereNotNull('resuelto_por')
                ->update(['estado' => AsistenciaHoraExtra::ESTADO_PENDIENTE, 'minutos_aprobados' => 0, 'motivo' => null, 'resuelto_por' => null, 'resuelto_at' => null]);

            $resumen = [
                'marcaciones_anuladas' => $marcacionesAnuladas,
                'salidas_desvinculadas' => $vinculadas->count(),
                'estados_forzados' => $estadosForzados,
                'dias_ajustados' => $diasAjustados,
                'calendario_revertido' => $calendarioEliminado + $horariosExcepcionales,
                'incidencias_reabiertas' => $incidenciasReabiertas,
                'horas_extra_reabiertas' => $horasExtraReabiertas,
            ];

            $this->auditoria->registrar(
                $empresa->id, $usuarioId, 'asistencia_cambios_manuales_descartados', 'rango_asistencia', $motivo, null,
                ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fechaHasta, 'colaborador_ids' => $colaboradorIds, ...$resumen],
            );

            return [...$resumen, 'fechas_adicionales' => $fechasAdicionales];
        });
    }
}
