<?php

namespace App\Modules\Asistencia\Http\Resources;

use App\Modules\Asistencia\Models\HorarioDia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Asistencia\Models\Horario
 */
class HorarioResource extends JsonResource
{
    private const NOMBRES_DIA = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $diaRepresentativo = $this->dias
            ->firstWhere('estado', 'laborable');

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tolerancia_minutos' => $this->tolerancia_minutos,
            'tipo_turno' => $this->tipo_turno,
            'descripcion' => $this->descripcion,
            'cruza_medianoche' => $this->cruza_medianoche,
            'vigencia_desde' => $this->vigencia_desde?->toDateString(),
            'vigencia_hasta' => $this->vigencia_hasta?->toDateString(),
            'activo' => $this->activo,
            'jornada' => $this->formatearRango($diaRepresentativo?->hora_entrada, $diaRepresentativo?->hora_salida),
            'refrigerio' => $this->formatearRango($diaRepresentativo?->refrigerio_inicio, $diaRepresentativo?->refrigerio_fin),
            'horas_dia' => $this->calcularHorasDia($diaRepresentativo),
            'config_pendiente' => $this->dias->contains('estado', 'pendiente'),
            'trabajadores' => $this->colaboradores_count ?? 0,
            'dias' => $this->dias->map(fn ($dia) => [
                'dia_semana' => $dia->dia_semana,
                'nombre_dia' => self::NOMBRES_DIA[$dia->dia_semana],
                'estado' => $dia->estado,
                'hora_entrada' => $dia->hora_entrada,
                'hora_salida' => $dia->hora_salida,
                'refrigerio_inicio' => $dia->refrigerio_inicio,
                'refrigerio_fin' => $dia->refrigerio_fin,
                'jornada_nocturna' => $dia->jornada_nocturna,
                'permitir_horas_extra' => $dia->permitir_horas_extra,
            ])->values(),
        ];
    }

    private function formatearRango(?string $desde, ?string $hasta): ?string
    {
        if (! $desde || ! $hasta) {
            return null;
        }

        return substr($desde, 0, 5).'-'.substr($hasta, 0, 5);
    }

    private function calcularHorasDia(?HorarioDia $dia): ?float
    {
        if (! $dia?->hora_entrada || ! $dia?->hora_salida) {
            return null;
        }

        $entrada = $this->horaAMinutos($dia->hora_entrada);
        $salida = $this->horaAMinutos($dia->hora_salida);
        $minutos = $salida - $entrada;
        if ($minutos <= 0) {
            $minutos += 24 * 60;
        }

        if ($dia->refrigerio_inicio && $dia->refrigerio_fin) {
            $refrigerioInicio = $this->horaAMinutos($dia->refrigerio_inicio);
            $refrigerioFin = $this->horaAMinutos($dia->refrigerio_fin);
            $minutosRefrigerio = $refrigerioFin - $refrigerioInicio;
            if ($minutosRefrigerio <= 0) {
                $minutosRefrigerio += 24 * 60;
            }
            $minutos -= $minutosRefrigerio;
        }

        return round(max(0, $minutos) / 60, 2);
    }

    private function horaAMinutos(string $hora): int
    {
        [$horas, $minutos] = array_map('intval', explode(':', $hora));

        return ($horas * 60) + $minutos;
    }
}
