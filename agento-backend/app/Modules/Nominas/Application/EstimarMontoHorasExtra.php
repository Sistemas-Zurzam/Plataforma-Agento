<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Nominas\Support\ValorHoraOrdinaria;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Monto REFERENCIAL de cada hora extra para la pestaña de Asistencia, con la
 * misma fórmula de los motores (valor hora × tasa del régimen × horas; valor
 * hora = remuneración ordinaria / 30 / jornada, o honorario / 240). No es lo que pagará la boleta: no considera trabajador de
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
            ->with('colaborador:id,empresa_id,tipo_contrato,regimen_laboral,tiene_hijos_asignacion_familiar')
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
        // Dependientes: mismo valor hora que la boleta (básico + asignación
        // familiar, jornada real del mes; D.S. 007-2002-TR art. 12).
        $valorHora = $base / 240;
        $nota = null;
        if (! $esHonorarios) {
            $af = $colaborador->tiene_hijos_asignacion_familiar ? (float) $parametros['asignacion_familiar_monto'] : 0.0;
            $mes = Carbon::parse($fecha);
            $jornada = ValorHoraOrdinaria::jornadaPromedio($colaborador->id, $mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString());
            $valorHora = ValorHoraOrdinaria::calcular($base, $af, $jornada);
            $nota = ValorHoraOrdinaria::avisoProvisional($jornada);
        }

        return [
            'monto' => round($valorHora * (float) $tasa * $minutos / 60, 2),
            'minutos' => $minutos,
            'base' => $base,
            'tasa' => (float) $tasa,
            'valor_hora' => round($valorHora, 4),
            'nota' => $nota,
        ];
    }
}
