<?php

namespace App\Modules\Asistencia\Domain\Plame;

use App\Modules\Asistencia\Models\AsistenciaPermiso;

/**
 * Traduce un permiso YA registrado por RR.HH. (hecho de negocio: tipo,
 * fechas, con/sin goce) al código oficial de Tabla 21 de SUNAT que le
 * correspondería en un futuro .snl — RR.HH. nunca elige el código, solo
 * registra el hecho real (ver CLAUDE.md/Sección 41 del encargo).
 *
 * NO genera ningún archivo — es una función de lectura reutilizable, deja
 * la resolución "consultable" para cuando exista el generador .snl real.
 *
 * Devuelve varios tramos cuando un mismo permiso cruza una regla de SUNAT
 * (ej. descanso médico que supera el día 20: los primeros 20 días son
 * código 20 a cargo del empleador, el resto código 21 subsidiado por
 * EsSalud — dos declaraciones distintas dentro del mismo permiso).
 */
class ResolverSuspensionSunat
{
    private const DIAS_A_CARGO_EMPLEADOR = 20;

    private const CODIGO_MEDICO_EMPLEADOR = '20';

    private const CODIGO_MEDICO_SUBSIDIADO = '21';

    private const CODIGO_CON_GOCE = '26';

    private const CODIGO_SIN_GOCE = '05';

    /**
     * @return array<int, array{codigo_sunat: ?string, fecha_inicio: string, fecha_fin: string, dias: int, motivo: ?string}>
     */
    public static function resolver(AsistenciaPermiso $permiso): array
    {
        $tipoAusencia = $permiso->tipoAusencia;

        if (! $tipoAusencia) {
            return [self::tramoSinResolver($permiso, 'Este permiso no tiene un tipo de ausencia asociado en el catálogo.')];
        }

        if ($tipoAusencia->sunat_no_aplica) {
            // Ej. "comisión de servicio": el colaborador sigue trabajando,
            // no corresponde declarar ningún día no laborado.
            return [];
        }

        if ($tipoAusencia->codigo === 'medico') {
            return self::resolverMedico($permiso);
        }

        if (filled($tipoAusencia->codigo_sunat_suspension)) {
            // Código fijo ya confirmado (vacaciones=23, falta_injustificada=07).
            return [self::tramoCompleto($permiso, $tipoAusencia->codigo_sunat_suspension)];
        }

        if (in_array($tipoAusencia->codigo, ['personal', 'capacitacion'], true)) {
            if ($permiso->con_goce === null) {
                return [self::tramoSinResolver($permiso, 'Falta indicar si este permiso fue con goce o sin goce de haber.')];
            }

            return [self::tramoCompleto($permiso, $permiso->con_goce ? self::CODIGO_CON_GOCE : self::CODIGO_SIN_GOCE)];
        }

        return [self::tramoSinResolver($permiso, $tipoAusencia->sunat_motivo_estado ?? 'Sin clasificación SUNAT determinada para este tipo de ausencia.')];
    }

    /**
     * Código SUNAT vigente para UN día puntual dentro de un permiso — útil
     * para un futuro recorrido día a día del .snl, sin repetir la lógica de
     * tramos.
     */
    public static function resolverParaFecha(AsistenciaPermiso $permiso, string $fecha): ?string
    {
        foreach (self::resolver($permiso) as $tramo) {
            if ($fecha >= $tramo['fecha_inicio'] && $fecha <= $tramo['fecha_fin']) {
                return $tramo['codigo_sunat'];
            }
        }

        return null;
    }

    /**
     * Los 20 días a cargo del empleador (código 20) se acumulan por AÑO
     * CALENDARIO, no por permiso individual — un descanso médico nuevo NO
     * reinicia el contador. Se calcula desde los permisos médicos ya
     * aprobados del mismo colaborador+año (nunca un contador mutable
     * guardado aparte, que podría desincronizarse).
     *
     * @return array<int, array{codigo_sunat: ?string, fecha_inicio: string, fecha_fin: string, dias: int, motivo: ?string}>
     */
    private static function resolverMedico(AsistenciaPermiso $permiso): array
    {
        $inicio = $permiso->fecha_inicio->copy();
        $fin = $permiso->fecha_fin->copy();
        $totalDias = $inicio->diffInDays($fin) + 1;

        $diasEmpleadorYaAcumulados = self::diasEmpleadorAcumuladosAntesDe($permiso);
        $diasEmpleadorDisponibles = max(0, self::DIAS_A_CARGO_EMPLEADOR - $diasEmpleadorYaAcumulados);

        if ($diasEmpleadorDisponibles === 0) {
            // El tope anual ya se agotó en descansos previos — este permiso
            // completo cae en el tramo subsidiado por EsSalud.
            return [[
                'codigo_sunat' => self::CODIGO_MEDICO_SUBSIDIADO,
                'fecha_inicio' => $inicio->toDateString(),
                'fecha_fin' => $fin->toDateString(),
                'dias' => $totalDias,
                'motivo' => null,
            ]];
        }

        $diasEnTramoEmpleador = min($diasEmpleadorDisponibles, $totalDias);
        $finTramoEmpleador = $inicio->copy()->addDays($diasEnTramoEmpleador - 1);

        $tramos = [[
            'codigo_sunat' => self::CODIGO_MEDICO_EMPLEADOR,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $finTramoEmpleador->toDateString(),
            'dias' => $diasEnTramoEmpleador,
            'motivo' => null,
        ]];

        if ($totalDias > $diasEnTramoEmpleador) {
            $inicioSubsidio = $finTramoEmpleador->copy()->addDay();
            $tramos[] = [
                'codigo_sunat' => self::CODIGO_MEDICO_SUBSIDIADO,
                'fecha_inicio' => $inicioSubsidio->toDateString(),
                'fecha_fin' => $fin->toDateString(),
                'dias' => $totalDias - $diasEnTramoEmpleador,
                'motivo' => null,
            ];
        }

        return $tramos;
    }

    /**
     * Simula, en orden cronológico, cuántos de los 20 días a cargo del
     * empleador ya se consumieron en descansos médicos APROBADOS previos
     * del mismo colaborador dentro del mismo año calendario (antes de la
     * fecha de inicio de $permiso). Cada permiso previo consume como máximo
     * lo que quedaba disponible en ese momento — una vez agotado el tope,
     * los permisos posteriores no vuelven a aportar al tramo empleador.
     */
    private static function diasEmpleadorAcumuladosAntesDe(AsistenciaPermiso $permiso): int
    {
        $anio = $permiso->fecha_inicio->year;

        $previos = AsistenciaPermiso::where('colaborador_id', $permiso->colaborador_id)
            ->where('estado', 'aprobado')
            ->whereYear('fecha_inicio', $anio)
            ->whereHas('tipoAusencia', fn ($q) => $q->where('codigo', 'medico'))
            ->where(function ($q) use ($permiso) {
                $q->where('fecha_inicio', '<', $permiso->fecha_inicio)
                    ->orWhere(function ($q2) use ($permiso) {
                        $q2->where('fecha_inicio', '=', $permiso->fecha_inicio)->where('id', '<', $permiso->id);
                    });
            })
            ->orderBy('fecha_inicio')
            ->orderBy('id')
            ->get(['id', 'fecha_inicio', 'fecha_fin']);

        $acumulado = 0;
        foreach ($previos as $previo) {
            $disponible = max(0, self::DIAS_A_CARGO_EMPLEADOR - $acumulado);
            if ($disponible === 0) {
                break;
            }

            $diasDelPrevio = $previo->fecha_inicio->diffInDays($previo->fecha_fin) + 1;
            $acumulado += min($disponible, $diasDelPrevio);
        }

        return $acumulado;
    }

    private static function tramoCompleto(AsistenciaPermiso $permiso, string $codigoSunat): array
    {
        return [
            'codigo_sunat' => $codigoSunat,
            'fecha_inicio' => $permiso->fecha_inicio->toDateString(),
            'fecha_fin' => $permiso->fecha_fin->toDateString(),
            'dias' => $permiso->fecha_inicio->diffInDays($permiso->fecha_fin) + 1,
            'motivo' => null,
        ];
    }

    private static function tramoSinResolver(AsistenciaPermiso $permiso, string $motivo): array
    {
        return [
            'codigo_sunat' => null,
            'fecha_inicio' => $permiso->fecha_inicio->toDateString(),
            'fecha_fin' => $permiso->fecha_fin->toDateString(),
            'dias' => $permiso->fecha_inicio->diffInDays($permiso->fecha_fin) + 1,
            'motivo' => $motivo,
        ];
    }
}
