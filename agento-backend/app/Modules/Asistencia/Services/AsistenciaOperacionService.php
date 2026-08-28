<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaImportacion;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\TipoAusencia;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class AsistenciaOperacionService
{
    /**
     * Consolida, para un colaborador y rango de fechas, cuántos días
     * correspondieron a cada tipo de ausencia — insumo directo para la
     * futura estructura .snl de PLAME (Tabla 21 SUNAT), que este método NO
     * genera todavía, solo deja consultable.
     *
     * No mapea el "estado" visual de asistencia directamente a un código:
     * los permisos aprobados (incluida "vacaciones") se agrupan por su
     * tipo_ausencia real, y las faltas que NO están cubiertas por ningún
     * permiso aprobado se clasifican aparte como "falta_injustificada" —
     * son naturalezas distintas aunque ambas dejen al colaborador sin
     * marcar asistencia ese día.
     *
     * @return array<int, array{codigo: string, nombre: string, codigo_sunat_suspension: ?string, dias: int}>
     */
    public function diasNoLaboradosPorTipo(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $porTipo = [];

        $permisos = AsistenciaPermiso::where('colaborador_id', $colaborador->id)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fechaFin)
            ->whereDate('fecha_fin', '>=', $fechaInicio)
            ->with('tipoAusencia')
            ->get();

        foreach ($permisos as $permiso) {
            $desde = $permiso->fecha_inicio->max(Carbon::parse($fechaInicio));
            $hasta = $permiso->fecha_fin->min(Carbon::parse($fechaFin));
            $dias = max(0, $desde->diffInDays($hasta) + 1);
            if ($dias === 0) {
                continue;
            }

            $codigo = $permiso->tipoAusencia?->codigo ?? $permiso->tipo;
            $porTipo[$codigo] ??= [
                'codigo' => $codigo,
                'nombre' => $permiso->tipoAusencia?->nombre ?? $permiso->tipo,
                'codigo_sunat_suspension' => $permiso->tipoAusencia?->codigo_sunat_suspension,
                'dias' => 0,
            ];
            $porTipo[$codigo]['dias'] += $dias;
        }

        // Faltas del período que ningún permiso aprobado cubre — se cuentan
        // aparte, nunca se asume que "falta" y "permiso" son lo mismo.
        $faltas = AsistenciaResultadoDiario::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->whereIn('estado', ['falta', 'falta_justificada'])
            ->count();

        if ($faltas > 0) {
            $tipoFalta = TipoAusencia::where('codigo', 'falta_injustificada')->first();
            $porTipo['falta_injustificada'] = [
                'codigo' => 'falta_injustificada',
                'nombre' => $tipoFalta?->nombre ?? 'Falta injustificada',
                'codigo_sunat_suspension' => $tipoFalta?->codigo_sunat_suspension,
                'dias' => $faltas,
            ];
        }

        return array_values($porTipo);
    }

    /**
     * Consolida, para un colaborador y rango de fechas, el total de minutos
     * ordinarios trabajados y de horas extra por cada recargo — insumo
     * directo para la futura estructura .jor de PLAME (E14, Tabla 21 SUNAT),
     * que este método NO genera todavía, solo deja consultable.
     *
     * @return array{minutos_ordinarios: int, minutos_extra_25: int, minutos_extra_35: int, minutos_extra_100: int, minutos_extra_total: int}
     */
    public function horasConsolidadasPorColaborador(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $totales = AsistenciaResultadoDiario::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->selectRaw('
                COALESCE(SUM(minutos_trabajados), 0) as minutos_ordinarios,
                COALESCE(SUM(minutos_extra_25), 0) as minutos_extra_25,
                COALESCE(SUM(minutos_extra_35), 0) as minutos_extra_35,
                COALESCE(SUM(minutos_extra_100), 0) as minutos_extra_100
            ')
            ->first();

        $minutosExtra25 = (int) ($totales->minutos_extra_25 ?? 0);
        $minutosExtra35 = (int) ($totales->minutos_extra_35 ?? 0);
        $minutosExtra100 = (int) ($totales->minutos_extra_100 ?? 0);

        return [
            'minutos_ordinarios' => (int) ($totales->minutos_ordinarios ?? 0),
            'minutos_extra_25' => $minutosExtra25,
            'minutos_extra_35' => $minutosExtra35,
            'minutos_extra_100' => $minutosExtra100,
            'minutos_extra_total' => $minutosExtra25 + $minutosExtra35 + $minutosExtra100,
        ];
    }

    /**
     * Vacaciones REALMENTE tomadas (hecho de asistencia, AsistenciaPermiso
     * tipo=vacaciones) en un rango, junto con la remuneración vacacional
     * estimada (sueldo diario = salario mensual / 30, convención estándar
     * de planilla peruana) — insumo directo para un futuro Tabla 22 código
     * 118 ("Remuneración vacacional"), que este método NO genera ni declara
     * todavía, solo deja consultable.
     *
     * IMPORTANTE: esto es DISTINTO de VACACIONES_PROVISION (la reserva
     * contable mensual en boleta_conceptos) — esa es dinero reservado, esto
     * es días efectivamente gozados. Determinar si PLAME exige declarar
     * esta porción separada del sueldo básico del mes (código 118) o si
     * basta con el código 121 ya declarado para todo el mes es una decisión
     * pendiente (ver entrega final) — este método solo deja el dato
     * consultable para cuando se resuelva.
     *
     * @return array{dias: int, permisos: int, sueldo_diario: float, remuneracion_vacacional_estimada: float}
     */
    public function vacacionesTomadas(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $permisos = AsistenciaPermiso::where('colaborador_id', $colaborador->id)
            ->where('tipo', 'vacaciones')
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fechaFin)
            ->whereDate('fecha_fin', '>=', $fechaInicio)
            ->get();

        $dias = 0;
        foreach ($permisos as $permiso) {
            $desde = $permiso->fecha_inicio->max(Carbon::parse($fechaInicio));
            $hasta = $permiso->fecha_fin->min(Carbon::parse($fechaFin));
            $dias += max(0, $desde->diffInDays($hasta) + 1);
        }

        $salarioMensual = (float) ($colaborador->remuneracionVigente?->salario ?? 0);
        $sueldoDiario = round($salarioMensual / 30, 2);

        return [
            'dias' => $dias,
            'permisos' => $permisos->count(),
            'sueldo_diario' => $sueldoDiario,
            'remuneracion_vacacional_estimada' => round($sueldoDiario * $dias, 2),
        ];
    }

    public function incidencias(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaIncidencia::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $estado === 'todos' ? $q : $q->where('estado', $estado))
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $colaboradorId) => $q->where('colaborador_id', $colaboradorId))
            ->when($filtros['busqueda'] ?? null, function ($q, $busqueda) {
                $q->whereHas('colaborador', function ($colaborador) use ($busqueda) {
                    $colaborador->where(function ($campos) use ($busqueda) {
                        $campos->where('nombres', 'like', "%{$busqueda}%")
                            ->orWhere('apellidos', 'like', "%{$busqueda}%")
                            ->orWhere('legajo', 'like', "%{$busqueda}%")
                            ->orWhere('numero_documento', 'like', "%{$busqueda}%");
                    });
                });
            })
            ->when($filtros['sede'] ?? null, fn ($q, $sede) => $q->whereHas('colaborador.sede', fn ($s) => $s->where('nombre', $sede)))
            ->when($filtros['area'] ?? null, fn ($q, $area) => $q->whereHas('colaborador.area', fn ($a) => $a->where('nombre', $area)))
            ->with(['colaborador:id,nombres,apellidos,legajo,area_id', 'colaborador.area', 'resultado'])
            ->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 25);
    }

    public function horasExtra(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaHoraExtra::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $estado === 'todos' ? $q : $q->where('estado', $estado))
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $colaboradorId) => $q->where('colaborador_id', $colaboradorId))
            ->with('colaborador:id,nombres,apellidos,legajo,area_id')
            ->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 25);
    }

    public function importaciones(Empresa $empresa, int $perPage): LengthAwarePaginator
    {
        return AsistenciaImportacion::query()->where('empresa_id', $empresa->id)
            ->latest()->paginate($perPage);
    }

    public function auditoria(Empresa $empresa, int $perPage, ?int $entidadId = null): LengthAwarePaginator
    {
        return AsistenciaAuditoria::query()->where('empresa_id', $empresa->id)
            ->when($entidadId, fn ($q) => $q->where('entidad_id', $entidadId))
            ->latest()->paginate($perPage);
    }
}
