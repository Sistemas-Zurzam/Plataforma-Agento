<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use App\Modules\Asistencia\Domain\Plame\ResolverSuspensionSunat;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;

/**
 * Estructura E15 (.snl) — Trabajador: Días subsidiados y otros no laborados.
 *
 * Campos exactos (Anexo 3, hoja "E15-Trab.DiaNoLabSub"), en este orden:
 *  1. Tipo de documento del trabajador      (Texto, Tabla 3)
 *  2. Número de documento del trabajador    (Texto, máx 15)
 *  3. Tipo de suspensión de la relación laboral (Texto, Tabla 21)
 *  4. Número de días de suspensión de labores   (Numérico, mín 0, máx 31 según el período)
 *
 * La estructura NO tiene fecha de inicio/fin ni un identificador de evento
 * — solo (trabajador, tipo de suspensión, días). Un mismo trabajador con
 * varios permisos aprobados del MISMO tipo de suspensión en el período se
 * CONSOLIDA en una sola línea con la suma de días (Sección 18): la
 * estructura oficial no deja espacio para declarar eventos por separado.
 *
 * Fuente: AsistenciaPermiso aprobados que se solapan con el período +
 * ResolverSuspensionSunat::resolver() (Sección 16) — nunca se reinterpreta
 * la regla de suspensión acá, solo se consolidan y recortan al período los
 * tramos que ese resolver ya determinó.
 */
final class SnlGenerator
{
    private const MAX_DIAS_POR_LINEA = 31;

    /**
     * @return array<int, array<int, string>>
     */
    public function generar(PlameExportContext $contexto): array
    {
        $colaboradorIds = $contexto->boletasPlanilla->pluck('colaborador_id');
        if ($colaboradorIds->isEmpty()) {
            return [];
        }

        $permisos = AsistenciaPermiso::whereIn('colaborador_id', $colaboradorIds)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $contexto->ciclo->fecha_fin)
            ->whereDate('fecha_fin', '>=', $contexto->ciclo->fecha_inicio)
            ->with(['tipoAusencia', 'colaborador'])
            ->get();

        /** @var array<string, int> $diasPorClave */
        $diasPorClave = [];
        /** @var array<int, Colaborador> $colaboradorPorId */
        $colaboradorPorId = [];

        foreach ($permisos as $permiso) {
            $colaborador = $permiso->colaborador;
            if (! $colaborador) {
                continue;
            }
            $colaboradorPorId[$colaborador->id] = $colaborador;

            foreach (ResolverSuspensionSunat::resolver($permiso) as $tramo) {
                if ($tramo['codigo_sunat'] === null) {
                    // PlameValidator ya debió bloquear esto antes de llegar
                    // acá (Sección 21) — el Generator no adivina.
                    throw PlameExportException::campoRequeridoFaltante("tipo_suspension (asistencia_permiso_id={$permiso->id})", $colaborador->id);
                }

                if (! preg_match('/^\d{2}$/', $tramo['codigo_sunat'])) {
                    // Formato canónico Tabla 21 (2 dígitos) — mismo resguardo
                    // que Tabla 22 en .rem, nunca se acepta un código sin
                    // validar su forma.
                    throw PlameExportException::formatoInvalido("tipo_suspension (asistencia_permiso_id={$permiso->id})", $tramo['codigo_sunat']);
                }

                $dias = $this->diasDentroDelPeriodo($tramo['fecha_inicio'], $tramo['fecha_fin'], $contexto->ciclo->fecha_inicio->toDateString(), $contexto->ciclo->fecha_fin->toDateString());
                if ($dias <= 0) {
                    // El tramo cae fuera del período que se está declarando
                    // (ej. permiso que cruza dos meses) — no corresponde a
                    // este archivo.
                    continue;
                }

                $clave = $colaborador->id.'|'.$tramo['codigo_sunat'];
                $diasPorClave[$clave] = ($diasPorClave[$clave] ?? 0) + $dias;
            }
        }

        $filas = [];
        foreach ($diasPorClave as $clave => $dias) {
            [$colaboradorId, $codigoSunat] = explode('|', $clave, 2);
            $colaborador = $colaboradorPorId[(int) $colaboradorId];

            if ($dias > self::MAX_DIAS_POR_LINEA) {
                throw PlameExportException::valorFueraDeRango("días de suspensión tipo {$codigoSunat} (colaborador_id={$colaboradorId})", $dias, 'máximo 31 días — Anexo 3, E15');
            }

            $tipoDocumento = $contexto->mapeos->codigo('tipo_documento', $colaborador->tipo_documento);

            $filas[] = [$tipoDocumento, $colaborador->numero_documento, $codigoSunat, (string) $dias];
        }

        // Orden determinístico (Sección 15): mismo criterio que .jor.
        usort($filas, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return $filas;
    }

    /**
     * Recorta un tramo (ya resuelto por ResolverSuspensionSunat, que puede
     * extenderse más allá del mes que se está declarando) a los días que
     * realmente caen dentro del período del ciclo — cada .snl mensual solo
     * declara los días de ESE mes, nunca el tramo completo si cruza meses.
     */
    private function diasDentroDelPeriodo(string $tramoInicio, string $tramoFin, string $periodoInicio, string $periodoFin): int
    {
        $desde = Carbon::parse($tramoInicio)->max(Carbon::parse($periodoInicio));
        $hasta = Carbon::parse($tramoFin)->min(Carbon::parse($periodoFin));

        return max(0, $desde->diffInDays($hasta) + 1);
    }
}
