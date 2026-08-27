<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\HorarioDia;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProcesarAsistenciaDiaria
{
    public function __construct(private readonly ResolverJornadaDiaria $resolverJornada) {}

    public function procesar(Colaborador $colaborador, Carbon $fecha): AsistenciaResultadoDiario
    {
        $fecha = $fecha->copy()->startOfDay();
        $periodoProtegido = AsistenciaPeriodo::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->whereIn('estado', ['cerrado', 'enviado_nomina'])
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->exists();
        abort_if($periodoProtegido, 422, 'La fecha pertenece a un período de asistencia protegido.');

        $jornada = $this->resolverJornada->resolver($colaborador, $fecha);
        // El sistema nunca adivina el día de descanso de un horario
        // rotativo -- si nadie lo declaró a mano para esta fecha, se
        // rechaza el procesamiento en vez de asumir laborable o descanso.
        abort_if(
            $jornada['tipo_dia'] === 'sin_rol_definido', 422,
            "Falta declarar el rol de turnos rotativos de {$colaborador->nombres} {$colaborador->apellidos} para el {$fecha->toDateString()}.",
        );
        $horarioDia = $jornada['horario_dia'];
        [$inicioProgramado, $finProgramado] = $this->limitesProgramados($fecha, $horarioDia);
        $marcaciones = $this->obtenerMarcaciones($colaborador, $fecha, $inicioProgramado, $finProgramado, (bool) $horarioDia?->jornada_nocturna);

        $entrada = $marcaciones->first()?->marcado_at;
        $salida = $marcaciones->count() > 1 ? $marcaciones->last()?->marcado_at : null;
        $esLaborable = in_array($jornada['tipo_dia'], ['laborable_presencial', 'home_office'], true);
        $permiso = AsistenciaPermiso::query()->where('empresa_id', $colaborador->empresa_id)
            ->where('colaborador_id', $colaborador->id)->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())->first();
        $estado = $permiso && $marcaciones->isEmpty()
            ? 'permiso'
            : $this->resolverEstado($jornada['tipo_dia'], $marcaciones->count());

        $minutosProgramados = $esLaborable
            ? $this->minutosProgramados($inicioProgramado, $finProgramado, $fecha, $horarioDia)
            : 0;
        $minutosTrabajados = $entrada && $salida
            ? (int) max(0, $entrada->diffInMinutes($salida) - $this->minutosRefrigerio($fecha, $horarioDia))
            : 0;
        $tolerancia = $colaborador->tolerancia_particular_minutos
            ?? $jornada['asignacion']?->horario?->tolerancia_minutos
            ?? 0;
        $tardanza = ! $permiso && $entrada && $inicioProgramado && $esLaborable
            ? (int) max(0, $inicioProgramado->diffInMinutes($entrada, false) - $tolerancia)
            : 0;
        $salidaAnticipada = ! $permiso && $salida && $finProgramado && $esLaborable
            ? (int) max(0, $salida->diffInMinutes($finProgramado, false))
            : 0;

        $extraObservada = $entrada && $salida
            ? $this->minutosExtraObservados($jornada['tipo_dia'], $minutosTrabajados, $minutosProgramados, $horarioDia)
            : 0;
        $esDiaDescanso = in_array($jornada['tipo_dia'], ['descanso', 'feriado'], true);

        return DB::transaction(function () use (
            $colaborador, $fecha, $jornada, $estado, $entrada, $salida,
            $minutosProgramados, $minutosTrabajados, $tardanza, $salidaAnticipada,
            $extraObservada, $esDiaDescanso, $marcaciones
        ) {
            $periodo = AsistenciaPeriodo::query()
                ->where('empresa_id', $colaborador->empresa_id)
                ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
                ->whereDate('fecha_fin', '>=', $fecha->toDateString())
                ->first();

            $resultado = AsistenciaResultadoDiario::query()->updateOrCreate(
                [
                    'empresa_id' => $colaborador->empresa_id,
                    'colaborador_id' => $colaborador->id,
                    'fecha' => $fecha->toDateString(),
                ],
                [
                    'periodo_id' => $periodo?->id,
                    'horario_asignacion_id' => $jornada['asignacion']?->id,
                    'tipo_dia' => $jornada['tipo_dia'],
                    'estado' => $estado,
                    'entrada_at' => $entrada,
                    'salida_at' => $salida,
                    'minutos_programados' => $minutosProgramados,
                    'minutos_trabajados' => $minutosTrabajados,
                    'minutos_tardanza' => $tardanza,
                    'minutos_salida_anticipada' => $salidaAnticipada,
                    'minutos_extra_observados' => $extraObservada,
                    'minutos_extra_25' => $esDiaDescanso ? 0 : min(120, $extraObservada),
                    'minutos_extra_35' => $esDiaDescanso ? 0 : max(0, $extraObservada - 120),
                    'minutos_extra_100' => $esDiaDescanso ? $extraObservada : 0,
                    'procesado_at' => now(),
                ]
            );

            $resultado->marcaciones()->sync($marcaciones->modelKeys());
            $this->sincronizarIncidencia($resultado, $estado);
            $this->sincronizarHorasExtra($resultado);

            return $resultado->load('marcaciones', 'incidencias');
        });
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function limitesProgramados(Carbon $fecha, ?HorarioDia $dia): array
    {
        if (! $dia?->hora_entrada || ! $dia->hora_salida) {
            return [null, null];
        }

        $inicio = Carbon::parse($fecha->toDateString().' '.$dia->hora_entrada);
        $fin = Carbon::parse($fecha->toDateString().' '.$dia->hora_salida);
        if ($dia->jornada_nocturna || $fin->lte($inicio)) {
            $fin->addDay();
        }

        return [$inicio, $fin];
    }

    private function obtenerMarcaciones(
        Colaborador $colaborador,
        Carbon $fecha,
        ?Carbon $inicio,
        ?Carbon $fin,
        bool $jornadaNocturna
    ) {
        $desde = $inicio?->copy()->subHours(6) ?? $fecha->copy()->startOfDay();
        $hasta = $fin?->copy()->addHours(6) ?? $fecha->copy()->endOfDay();
        if (! $jornadaNocturna) {
            // Sin jornada nocturna, ninguna marcación del turno siguiente debe
            // poder sustituir la salida de hoy — protege contra un
            // finProgramado corrido a mañana (p. ej. hora_salida mal
            // configurada, <= hora_entrada) que de otro modo "roba" la
            // marcación de entrada del día siguiente como si fuera la salida
            // de hoy, inflando horas extra/tardanza de forma masiva.
            $hasta = $hasta->minimum($fecha->copy()->endOfDay());
        }

        return AsistenciaMarcacion::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->whereNull('anulada_at')
            ->where(function ($query) use ($colaborador) {
                $query->where('colaborador_id', $colaborador->id)
                    ->orWhere('person_id', $colaborador->numero_documento);
            })
            ->whereBetween('marcado_at', [$desde, $hasta])
            ->orderBy('marcado_at')
            ->get();
    }

    private function resolverEstado(string $tipoDia, int $cantidadMarcaciones): string
    {
        if ($cantidadMarcaciones === 1) {
            return 'marcacion_incompleta';
        }
        if ($cantidadMarcaciones >= 2) {
            return 'presente';
        }
        if ($tipoDia === 'home_office') {
            return 'home_office';
        }
        if ($tipoDia === 'laborable_presencial') {
            return 'falta';
        }

        return $tipoDia;
    }

    private function minutosProgramados(?Carbon $inicio, ?Carbon $fin, Carbon $fecha, ?HorarioDia $dia): int
    {
        if (! $inicio || ! $fin) {
            return 0;
        }

        return (int) max(0, $inicio->diffInMinutes($fin) - $this->minutosRefrigerio($fecha, $dia));
    }

    private function minutosRefrigerio(Carbon $fecha, ?HorarioDia $dia): int
    {
        if (! $dia?->refrigerio_inicio || ! $dia->refrigerio_fin) {
            return 0;
        }

        $inicio = Carbon::parse($fecha->toDateString().' '.$dia->refrigerio_inicio);
        $fin = Carbon::parse($fecha->toDateString().' '.$dia->refrigerio_fin);

        return $fin->gt($inicio) ? (int) $inicio->diffInMinutes($fin) : 0;
    }

    private function minutosExtraObservados(
        string $tipoDia,
        int $trabajados,
        int $programados,
        ?HorarioDia $dia
    ): int {
        if (in_array($tipoDia, ['descanso', 'feriado'], true)) {
            return $trabajados;
        }
        if (! $dia?->permitir_horas_extra) {
            return 0;
        }

        return max(0, $trabajados - $programados);
    }

    private function sincronizarIncidencia(AsistenciaResultadoDiario $resultado, string $estado): void
    {
        $tiposAutomaticos = ['falta', 'marcacion_incompleta'];
        if (! in_array($estado, $tiposAutomaticos, true)) {
            $resultado->incidencias()
                ->whereIn('tipo', $tiposAutomaticos)
                ->where('estado', 'pendiente')
                ->delete();

            return;
        }

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultado->id, 'tipo' => $estado],
            [
                'empresa_id' => $resultado->empresa_id,
                'colaborador_id' => $resultado->colaborador_id,
                'fecha' => $resultado->fecha,
                'estado' => 'pendiente',
                'descripcion' => $estado === 'falta'
                    ? 'No se encontraron marcaciones para una jornada laborable.'
                    : 'Solo se encontró una marcación para la jornada.',
            ]
        );
    }

    private function sincronizarHorasExtra(AsistenciaResultadoDiario $resultado): void
    {
        $tramos = [
            '25' => $resultado->minutos_extra_25,
            '35' => $resultado->minutos_extra_35,
            '100' => $resultado->minutos_extra_100,
        ];
        foreach ($tramos as $tasa => $minutos) {
            $registro = AsistenciaHoraExtra::query()->firstOrNew([
                'resultado_diario_id' => $resultado->id,
                'tasa' => $tasa,
            ]);
            if ($minutos <= 0) {
                if ($registro->exists && $registro->estado === 'pendiente') $registro->delete();
                continue;
            }
            if ($registro->exists && $registro->estado !== 'pendiente') continue;
            $registro->fill([
                'empresa_id' => $resultado->empresa_id,
                'colaborador_id' => $resultado->colaborador_id,
                'fecha' => $resultado->fecha,
                'minutos_observados' => $minutos,
                'minutos_solicitados' => $minutos,
                'estado' => 'pendiente',
            ])->save();
        }
    }
}
