<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReporteBonoAsistenciaService
{
    public function generar(Empresa $empresa, string $mes, ?int $areaId = null): array
    {
        if ($mes < '2026-08') {
            throw ValidationException::withMessages(['mes' => 'Estos criterios se aplican desde agosto de 2026.']);
        }
        $inicio = Carbon::parse($mes.'-01')->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();
        $areas = \App\Modules\Configuracion\Models\Area::withoutGlobalScopes()->where('empresa_id', $empresa->id)->orderBy('nombre')->get(['id', 'nombre']);
        $areaId ??= $areas->first(fn ($a) => strtoupper(trim($a->nombre)) === 'VENTAS')?->id;
        if ($areaId !== null && ! $areas->contains('id', $areaId)) throw ValidationException::withMessages(['area_id' => 'El área no pertenece a la empresa.']);
        $personas = Colaborador::withoutGlobalScopes()->withTrashed()->where('empresa_id', $empresa->id)
            ->where('area_id', $areaId ?? -1)
            ->whereDate('fecha_ingreso', '<=', $fin)
            ->where(fn ($q) => $q->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', $inicio))
            ->orderBy('apellidos')->get();
        $resultados = AsistenciaResultadoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->with(['marcaciones' => fn ($q) => $q->withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereNull('anulada_at')])
            ->get()->groupBy('colaborador_id');
        $permisos = AsistenciaPermiso::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->where('estado', 'aprobado')->whereDate('fecha_inicio', '<=', $fin)->whereDate('fecha_fin', '>=', $inicio)
            ->with('tipoAusencia')->get()->groupBy('colaborador_id');
        return ['empresa' => $empresa->nombre_comercial, 'mes' => $mes, 'area_id' => $areaId, 'areas' => $areas->toArray(), 'generado_en' => now()->toIso8601String(),
            'colaboradores' => $personas->map(function ($persona) use ($resultados, $permisos, $inicio, $fin) {
                $dias = ($resultados[$persona->id] ?? collect())->unique(fn ($r) => $r->fecha->toDateString());
                $stats = ['dias_efectivos' => 0, 'descansos' => 0, 'tardanzas' => 0, 'faltas_justificadas' => 0,
                    'faltas_injustificadas' => 0, 'sin_huellero_completo' => 0, 'incumplimientos_turno' => 0,
                    'dias_sin_clasificar' => 0, 'permisos_por_revisar' => 0];
                foreach ($dias as $dia) {
                    $trabajoCompleto = $dia->entrada_at && $dia->salida_at && $dia->salida_at->gt($dia->entrada_at) && $dia->minutos_trabajados > 0;
                    // El colaborador sí asistió (hay marca de entrada) pero le falta o
                    // quedó fuera de horario la marca de salida — cuenta como día
                    // efectivo igual, pero se señala vía "sin_huellero_completo" (ya
                    // no bloqueante) para que RR. HH. revise y corrija el registro.
                    $marcacionIncompletaConAsistencia = ! $trabajoCompleto && $dia->estado === 'marcacion_incompleta' && $dia->entrada_at;
                    $trabajo = $trabajoCompleto || $marcacionIncompletaConAsistencia;
                    if ($trabajo) {
                        $stats['dias_efectivos']++;
                        if ($trabajoCompleto) {
                            $marcas = $dia->marcaciones->filter(fn ($m) => $m->colaborador_id === $persona->id && in_array(mb_strtolower(trim($m->origen)), ['attendance_device', 'attendance device', 'biometrico', 'huellero'], true));
                            if (! $marcas->contains(fn ($m) => $m->marcado_at->equalTo($dia->entrada_at))
                                || ! $marcas->contains(fn ($m) => $m->marcado_at->equalTo($dia->salida_at))) $stats['sin_huellero_completo']++;
                        } else {
                            $stats['sin_huellero_completo']++;
                        }
                    } elseif ($dia->estado === 'descanso' || $dia->tipo_dia === 'descanso') {
                        $stats['descansos']++;
                    }
                    if ($dia->minutos_tardanza > 0) $stats['tardanzas']++;
                    if ($dia->minutos_salida_anticipada > 0 || in_array($dia->estado, ['horas_incompletas', 'horario_desplazado'], true)) $stats['incumplimientos_turno']++;
                    if ($dia->estado === 'dia_sin_clasificar') $stats['dias_sin_clasificar']++;
                    if (! $trabajo && in_array($dia->estado, ['falta', 'permiso'], true)) {
                        $permiso = ($permisos[$persona->id] ?? collect())->first(fn ($p) => $p->fecha_inicio->lte($dia->fecha) && $p->fecha_fin->gte($dia->fecha));
                        if ($permiso && in_array($permiso->tipoAusencia?->codigo ?? $permiso->tipo, ['personal', 'medico', 'falta_justificada'], true) && filled($permiso->motivo)) {
                            $stats['faltas_justificadas']++;
                            $stats['permisos_por_revisar']++;
                        } elseif ($dia->estado === 'falta' && (! $permiso || $permiso->tipoAusencia?->codigo === 'falta_injustificada')) {
                            $stats['faltas_injustificadas']++;
                        } else {
                            $stats['permisos_por_revisar']++;
                        }
                    }
                }
                $stats['dias_sin_resultado'] = max(0, $inicio->daysInMonth - $dias->count());
                $observaciones = [];
                if ($fin->isFuture()) $observaciones[] = 'Mes en curso: evaluación provisional';
                if ($persona->fecha_ingreso->gt($inicio) || ($persona->fecha_cese && $persona->fecha_cese->lt($fin))) $observaciones[] = 'Ingreso o cese durante el mes';
                return ['colaborador_id' => $persona->id, 'colaborador' => trim($persona->nombres.' '.$persona->apellidos),
                    'documento' => $persona->numero_documento, ...$stats, ...self::evaluar($stats, $observaciones)];
            })->values()->all()];
    }

    public static function evaluar(array $s, array $observaciones = []): array
    {
        if ($s['dias_efectivos'] + $s['faltas_justificadas'] < 26) $observaciones[] = 'No completa 26 jornadas (incluidas las faltas justificadas sujetas a excepción)';
        if ($s['descansos'] !== 4) $observaciones[] = 'Revisar los 4 descansos del mes';
        foreach (['sin_huellero_completo' => 'Revisar ingreso y salida del huellero', 'incumplimientos_turno' => 'Validar horario y autorizaciones previas',
            'dias_sin_clasificar' => 'Días sin clasificar', 'dias_sin_resultado' => 'Faltan resultados diarios',
            'permisos_por_revisar' => 'RR. HH. debe validar el tipo de ausencia y su sustento'] as $campo => $mensaje) {
            if ($s[$campo] > 0) $observaciones[] = $mensaje;
        }
        $inicial = match ($s['faltas_justificadas']) { 0 => 100, 1 => 50, default => 0 };
        $recuperable = in_array($s['faltas_justificadas'], [1, 2], true) ? 50 : 0;
        $excluido = $s['faltas_injustificadas'] > 0 || $s['tardanzas'] >= 3;
        if ($excluido) {
            $inicial = $recuperable = 0;
            $observaciones[] = $s['faltas_injustificadas'] > 0 ? 'Falta injustificada: sin recuperación' : '3 o más tardanzas: sin recuperación';
        }
        if ($s['faltas_justificadas'] > 2) $observaciones[] = 'Más de 2 faltas justificadas: Gerencia debe definir el tratamiento';
        return ['porcentaje_inicial' => $inicial, 'porcentaje_recuperable' => $recuperable,
            'evaluacion' => $excluido ? 'No cumple' : ($observaciones ? 'Requiere revisión' : 'Candidato'),
            'observaciones' => implode('; ', $observaciones), 'aprobacion_gerencia' => 'Pendiente'];
    }
}
