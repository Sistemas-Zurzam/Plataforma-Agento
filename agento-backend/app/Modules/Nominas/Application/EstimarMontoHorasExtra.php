<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Support\FeriadosPeru;
use RuntimeException;

/**
 * Monto REFERENCIAL de cada hora extra para la pestaña de Asistencia, con la
 * misma fórmula de los motores (sueldo u honorario / 240 × tasa del régimen
 * × horas). No es lo que pagará la boleta: no considera trabajador de
 * confianza, "contabilizar horas extra" ni el tope del ciclo — eso lo decide
 * el cálculo de la planilla.
 */
class EstimarMontoHorasExtra
{
    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{monto: ?float, minutos: int, base: ?float, tasa: ?float, nota: ?string}>
     */
    public function paraIds(Empresa $empresa, array $ids): array
    {
        $horasExtra = AsistenciaHoraExtra::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('id', $ids)
            ->with('colaborador:id,empresa_id,tipo_contrato,regimen_laboral')
            ->get();

        $montos = [];
        foreach ($horasExtra as $horaExtra) {
            $montos[$horaExtra->id] = $this->estimar($empresa, $horaExtra);
        }

        return $montos;
    }

    private function estimar(Empresa $empresa, AsistenciaHoraExtra $horaExtra): array
    {
        $fecha = $horaExtra->fecha->toDateString();
        $colaborador = $horaExtra->colaborador;
        // Aprobadas si ya hay decisión; si no, lo observado (lo que se
        // pagaría al aprobarla completa).
        $minutos = (int) ($horaExtra->minutos_aprobados > 0 ? $horaExtra->minutos_aprobados : $horaExtra->minutos_observados);
        $sinMonto = fn (string $nota) => ['monto' => null, 'minutos' => $minutos, 'base' => null, 'tasa' => null, 'nota' => $nota];

        if (! $colaborador) {
            return $sinMonto('Colaborador no disponible.');
        }

        $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios'
            || $colaborador->regimen_laboral === 'Locacion de Servicios';
        $tasaTexto = (string) $horaExtra->tasa;

        if ($esHonorarios && $tasaTexto === '100' && FeriadosPeru::esFeriado($fecha)) {
            return $sinMonto('En honorarios el feriado se paga como un día de honorario, no como hora extra.');
        }

        // Mismo criterio que el honorario por feriado: si la remuneración se
        // registró con vigencia posterior, se usa la del período.
        $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fecha)
            ->orderByDesc('vigencia_desde')->orderByDesc('id')
            ->first()
            ?? ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                ->orderBy('vigencia_desde')->orderBy('id')
                ->first();
        if (! $remuneracion || (float) $remuneracion->salario <= 0) {
            return $sinMonto('Sin remuneración registrada.');
        }

        try {
            $parametros = $esHonorarios
                ? ParametrosVigentesResolver::paraHonorarios($empresa, $fecha)
                : ParametrosVigentesResolver::paraRegimen($empresa, $colaborador->regimen_laboral ?: 'General', $fecha);
        } catch (RuntimeException) {
            return $sinMonto('Faltan parámetros laborales del régimen.');
        }

        $tasa = match ($tasaTexto) {
            '25' => $parametros['horas_extra_tasa_x25'],
            '35' => $parametros['horas_extra_tasa_x35'],
            '100' => $parametros['horas_extra_tasa_nocturna'],
            default => null,
        };
        if ($tasa === null) {
            return $sinMonto('Tasa no reconocida.');
        }

        $base = (float) $remuneracion->salario;

        return [
            'monto' => round($base / 240 * (float) $tasa * $minutos / 60, 2),
            'minutos' => $minutos,
            'base' => $base,
            'tasa' => (float) $tasa,
            'nota' => null,
        ];
    }
}
