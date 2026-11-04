<?php

namespace App\Modules\Asistencia\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Llenado inicial de la marca de ajuste manual (migración 000009) para los
 * días corregidos antes de que existiera. Solo marca los días que TODAVÍA
 * conservan evidencia del ajuste; un día cuya edición ya se perdió en un
 * reproceso anterior, o que se restableció, no se congela en un estado que
 * RR.HH. no decidió.
 *
 * Evidencia aceptada:
 * - estado_forzado registrado;
 * - una marcación manual de RR.HH. vigente ese día;
 * - un horario excepcional asignado al día;
 * - una edición auditada que cambió el estado sin marcaciones ni horario
 *   (solo pudo ser un estado forzado) y cuyo estado sigue vigente, siempre
 *   que el rango no se haya restablecido después. En ese caso también se
 *   recupera estado_forzado, que antes no se guardaba.
 *
 * Usa consultas directas (no modelos) para no depender de scopes ni de la
 * forma futura de los modelos.
 */
class MarcarDiasAjustadosExistentes
{
    private const ENTIDAD_RESULTADO = 'App\\Modules\\Asistencia\\Models\\AsistenciaResultadoDiario';

    /** @return int días marcados */
    public function ejecutar(): int
    {
        $ediciones = DB::table('asistencia_auditorias')
            ->where('accion', 'resultado_editado')
            ->where('entidad_tipo', self::ENTIDAD_RESULTADO)
            ->whereNotNull('entidad_id')
            ->orderBy('id')
            ->get(['entidad_id', 'usuario_id', 'motivo', 'antes', 'despues', 'created_at'])
            ->groupBy('entidad_id');

        $porMarcarId = [];
        $estadoRecuperado = [];

        foreach (DB::table('asistencia_resultados_diarios')->whereNotNull('estado_forzado')->pluck('id') as $id) {
            $porMarcarId[$id] = true;
        }

        $clavesConEvidencia = [];
        DB::table('asistencia_marcaciones')
            ->where('origen', 'manual_rrhh')->whereNull('anulada_at')->whereNotNull('colaborador_id')
            ->get(['colaborador_id', 'marcado_at'])
            ->each(function ($marca) use (&$clavesConEvidencia) {
                $clavesConEvidencia[$marca->colaborador_id.'|'.Carbon::parse($marca->marcado_at)->toDateString()] = true;
            });
        DB::table('colaborador_calendario_dias')
            ->whereNotNull('horario_excepcional_id')
            ->get(['colaborador_id', 'fecha'])
            ->each(function ($dia) use (&$clavesConEvidencia) {
                $clavesConEvidencia[$dia->colaborador_id.'|'.Carbon::parse($dia->fecha)->toDateString()] = true;
            });

        $restablecimientos = DB::table('asistencia_auditorias')
            ->where('accion', 'asistencia_cambios_manuales_descartados')
            ->get(['despues', 'created_at'])
            ->map(fn ($fila) => ['datos' => json_decode((string) $fila->despues, true) ?? [], 'en' => Carbon::parse($fila->created_at)]);

        foreach (array_keys($clavesConEvidencia) as $clave) {
            [$colaboradorId, $fecha] = explode('|', $clave);
            $id = DB::table('asistencia_resultados_diarios')
                ->where('colaborador_id', (int) $colaboradorId)->whereDate('fecha', $fecha)->value('id');
            if ($id) {
                $porMarcarId[$id] = true;
            }
        }

        $candidatos = DB::table('asistencia_resultados_diarios')
            ->whereIn('id', $ediciones->keys())
            ->get(['id', 'colaborador_id', 'fecha', 'estado']);

        foreach ($candidatos as $resultado) {
            if (isset($porMarcarId[$resultado->id])) {
                continue;
            }
            $fecha = Carbon::parse($resultado->fecha)->toDateString();
            $ultima = $ediciones->get($resultado->id)?->last();
            if (! $ultima) {
                continue;
            }
            $antes = json_decode((string) $ultima->antes, true) ?? [];
            $despues = json_decode((string) $ultima->despues, true) ?? [];
            $estadoEditado = $despues['estado'] ?? null;
            $cambioEstado = $estadoEditado !== null && ($antes['estado'] ?? null) !== $estadoEditado;
            $restablecidoDespues = $restablecimientos->contains(fn ($r) => $r['en']->gt(Carbon::parse($ultima->created_at))
                && $fecha >= ($r['datos']['fecha_desde'] ?? '9999-12-31') && $fecha <= ($r['datos']['fecha_hasta'] ?? '0000-01-01')
                && (empty($r['datos']['colaborador_ids']) || in_array($resultado->colaborador_id, $r['datos']['colaborador_ids'])));

            if ($cambioEstado && $resultado->estado === $estadoEditado && ! $restablecidoDespues) {
                $porMarcarId[$resultado->id] = true;
                $estadoRecuperado[$resultado->id] = $estadoEditado;
            }
        }

        $marcados = 0;
        foreach (array_keys($porMarcarId) as $id) {
            $ultima = $ediciones->get($id)?->last();
            $cambios = [
                'ajustado_manualmente_at' => $ultima->created_at ?? DB::raw('updated_at'),
                'ajustado_por' => $ultima->usuario_id ?? null,
                'motivo_ajuste' => $ultima->motivo ?? null,
            ];
            if (isset($estadoRecuperado[$id])) {
                $cambios['estado_forzado'] = $estadoRecuperado[$id];
            }
            $marcados += DB::table('asistencia_resultados_diarios')
                ->where('id', $id)->whereNull('ajustado_manualmente_at')->update($cambios);
        }

        return $marcados;
    }
}
