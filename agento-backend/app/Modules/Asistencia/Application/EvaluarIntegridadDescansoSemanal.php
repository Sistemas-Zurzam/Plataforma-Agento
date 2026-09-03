<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Descanso semanal flexible automático — Sección 4 del plan. Servicio
 * APARTE de AsignarDescansoFlexibleSemanal, con un contrato deliberadamente
 * estrecho para que sea imposible confundirlo con una puerta de escritura
 * hacia periodos cerrados:
 *
 *  - Puede leer asistencia de cualquier periodo, abierto o cerrado.
 *  - Nunca llama a ProcesarAsistenciaDiaria::procesar().
 *  - Nunca crea, elimina ni modifica colaborador_calendario_dias.
 *  - Nunca modifica un AsistenciaResultadoDiario.
 *  - Su única escritura permitida: updateOrCreate de una AsistenciaIncidencia
 *    semanal, anclada al resultado diario del DOMINGO de la semana evaluada
 *    (reutiliza la unique (resultado_diario_id, tipo) ya existente en
 *    asistencia_incidencias -- cero riesgo de duplicados, cero migración
 *    nueva). No necesita ni toca el candado de periodo porque nunca ejecuta
 *    una operación que lo requiera.
 *
 * Se invoca únicamente cuando el segmento recién persistido de una semana
 * incluye su domingo -- para entonces el resultado diario del domingo ya
 * existe (AsegurarCoberturaAsistenciaPeriodo lo garantiza incluso para un
 * día sin_rol_definido, y persistirSegmento() lo reprocesa si le tocó
 * clasificación automática).
 */
class EvaluarIntegridadDescansoSemanal
{
    private const ESTADOS_TRABAJO_EFECTIVO = ['presente', 'horas_incompletas', 'horario_desplazado'];

    public function __construct(
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    public function evaluar(Empresa $empresa, Colaborador $colaborador, Carbon $inicioSemana, int $diasDescansoRequeridos): void
    {
        $finSemana = $inicioSemana->copy()->addDays(6);

        $resultados = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha', '>=', $inicioSemana->toDateString())
            ->whereDate('fecha', '<=', $finSemana->toDateString())
            ->get()
            ->keyBy(fn (AsistenciaResultadoDiario $resultado) => $resultado->fecha->toDateString());

        $resultadoDomingo = $resultados->get($finSemana->toDateString());
        if (! $resultadoDomingo) {
            // No debería pasar (persistirSegmento() ya reprocesó el domingo
            // antes de invocar esto) -- sin ancla no hay dónde escribir la
            // incidencia, así que no se evalúa nada.
            return;
        }

        [$diasConDescanso, $diasConPermisoOFeriado, $diasTrabajadosEfectivos] = $this->contarDias($resultados, $inicioSemana, $finSemana);

        $diasAplicables = 7 - $diasConPermisoOFeriado;
        $descansosPendientes = max(0, $diasDescansoRequeridos - $diasConDescanso);

        // Feriados y permisos NUNCA sustituyen ni consumen un cupo de
        // descanso semanal configurado (D.Leg. 713 regula ambos por
        // separado) -- por eso diasAplicables solo se usa para decidir la
        // severidad de la alerta, nunca para reducir descansosPendientes.
        $tipo = match (true) {
            $diasConDescanso === 0 && $diasTrabajadosEfectivos === $diasAplicables && $diasAplicables > 0 => AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL,
            $descansosPendientes > 0 => AsistenciaIncidencia::TIPO_DESCANSO_FLEXIBLE_INCOMPLETO,
            default => null,
        };

        $this->sincronizarIncidenciasSemanales($empresa, $resultadoDomingo, $tipo, $inicioSemana, $finSemana, $diasConDescanso, $diasDescansoRequeridos, $diasTrabajadosEfectivos, $diasAplicables);
    }

    /** @return array{0: int, 1: int, 2: int} [diasConDescanso, diasConPermisoOFeriado, diasTrabajadosEfectivos] */
    private function contarDias(Collection $resultados, Carbon $inicioSemana, Carbon $finSemana): array
    {
        $diasConDescanso = 0;
        $diasConPermisoOFeriado = 0;
        $diasTrabajadosEfectivos = 0;

        for ($fecha = $inicioSemana->copy(); $fecha->lte($finSemana); $fecha->addDay()) {
            $resultado = $resultados->get($fecha->toDateString());
            if (! $resultado) {
                continue;
            }
            if ($resultado->tipo_dia === 'descanso') {
                $diasConDescanso++;

                continue;
            }
            if ($resultado->tipo_dia === 'feriado' || $resultado->estado === 'permiso') {
                $diasConPermisoOFeriado++;

                continue;
            }
            if (in_array($resultado->estado, self::ESTADOS_TRABAJO_EFECTIVO, true)) {
                $diasTrabajadosEfectivos++;
            }
        }

        return [$diasConDescanso, $diasConPermisoOFeriado, $diasTrabajadosEfectivos];
    }

    private function sincronizarIncidenciasSemanales(
        Empresa $empresa,
        AsistenciaResultadoDiario $resultadoDomingo,
        ?string $tipoVigente,
        Carbon $inicioSemana,
        Carbon $finSemana,
        int $diasConDescanso,
        int $diasDescansoRequeridos,
        int $diasTrabajadosEfectivos,
        int $diasAplicables,
    ): void {
        $tiposSemanales = [AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL, AsistenciaIncidencia::TIPO_DESCANSO_FLEXIBLE_INCOMPLETO];

        foreach ($tiposSemanales as $otroTipo) {
            if ($otroTipo === $tipoVigente) {
                continue;
            }
            $obsoleta = AsistenciaIncidencia::query()
                ->where('resultado_diario_id', $resultadoDomingo->id)
                ->where('tipo', $otroTipo)
                ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)
                ->first();
            if ($obsoleta) {
                $antes = $obsoleta->toArray();
                $obsoleta->delete();
                $this->auditoria->registrar(
                    $empresa->id, null, 'descanso_flexible_integridad_auto_eliminada', $resultadoDomingo,
                    'La semana se reevaluó y esta incidencia automática ya no aplica.', $antes, null,
                );
            }
        }

        if ($tipoVigente === null) {
            return;
        }

        $descripcion = $this->describir($tipoVigente, $inicioSemana, $finSemana, $diasConDescanso, $diasDescansoRequeridos, $diasTrabajadosEfectivos, $diasAplicables);

        $existente = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $resultadoDomingo->id)
            ->where('tipo', $tipoVigente)
            ->first();
        if ($existente && $existente->estado !== AsistenciaIncidencia::ESTADO_PENDIENTE) {
            // Ya fue revisada por una persona -- nunca se reabre sola.
            // Fechas/periodo consultados quedan igual en la auditoría, para
            // que quede constancia de que el diagnóstico automático sigue
            // marcando un problema y requiere regularización manual.
            $this->auditoria->registrar(
                $empresa->id, null, 'descanso_flexible_integridad_requiere_regularizacion', $resultadoDomingo,
                "El diagnóstico automático de la semana {$inicioSemana->toDateString()}–{$finSemana->toDateString()} sigue marcando '{$tipoVigente}' pero la incidencia ya fue resuelta manualmente.",
                null, null,
            );

            return;
        }

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultadoDomingo->id, 'tipo' => $tipoVigente],
            [
                'empresa_id' => $empresa->id,
                'colaborador_id' => $resultadoDomingo->colaborador_id,
                'fecha' => $resultadoDomingo->fecha,
                'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
                'descripcion' => $descripcion,
            ]
        );
    }

    private function describir(string $tipo, Carbon $inicioSemana, Carbon $finSemana, int $diasConDescanso, int $diasDescansoRequeridos, int $diasTrabajadosEfectivos, int $diasAplicables): string
    {
        $rango = "{$inicioSemana->toDateString()} a {$finSemana->toDateString()}";

        return match ($tipo) {
            AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL => "Semana {$rango}: el colaborador trabajó efectivamente los {$diasTrabajadosEfectivos} de {$diasAplicables} días aplicables y no tuvo ningún descanso semanal. Revisar y, si corresponde, asignar un descanso retroactivo (dispara pago/sustitutorio).",
            AsistenciaIncidencia::TIPO_DESCANSO_FLEXIBLE_INCOMPLETO => 'Semana '.$rango.": no fue posible asignar {$diasConDescanso} de los {$diasDescansoRequeridos} descansos configurados. Requiere revisión.",
            default => "Semana {$rango}: revisar descanso semanal flexible.",
        };
    }
}
