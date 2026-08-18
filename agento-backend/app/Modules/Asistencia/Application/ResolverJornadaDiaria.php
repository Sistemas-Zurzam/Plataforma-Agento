<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\HorarioDia;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use App\Modules\Personas\Support\CalendarioMensualGenerator;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Support\Carbon;

class ResolverJornadaDiaria
{
    /**
     * @return array{tipo_dia: string, asignacion: ?ColaboradorHorarioAsignacion, horario_dia: ?HorarioDia}
     */
    public function resolver(Colaborador $colaborador, Carbon $fecha): array
    {
        $fechaTexto = $fecha->toDateString();
        $asignacion = ColaboradorHorarioAsignacion::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaTexto)
            ->where(function ($query) use ($fechaTexto) {
                $query->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $fechaTexto);
            })
            ->with('horario.dias')
            ->orderByDesc('vigencia_desde')
            ->first();

        $horarioDia = $asignacion?->horario?->dias
            ->firstWhere('dia_semana', $fecha->dayOfWeekIso - 1);

        $calendario = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha', $fechaTexto)
            ->first();

        if ($calendario === null) {
            // El calendario inicial solo cubre el mes de ingreso; para meses
            // posteriores se genera aquí bajo demanda (hereda el patrón por
            // día de semana del último mes con datos) y queda persistido.
            CalendarioMensualGenerator::paraMes($colaborador, $fecha->year, $fecha->month);
            $calendario = ColaboradorCalendarioDia::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereDate('fecha', $fechaTexto)
                ->first();
        }

        $tipoDia = match (true) {
            $calendario !== null => $calendario->tipo,
            FeriadosPeru::esFeriado($fechaTexto) => 'feriado',
            $horarioDia === null => 'no_programado',
            $horarioDia->estado === 'descanso' => 'descanso',
            default => 'laborable_presencial',
        };

        return [
            'tipo_dia' => $tipoDia,
            'asignacion' => $asignacion,
            'horario_dia' => $horarioDia,
        ];
    }
}
