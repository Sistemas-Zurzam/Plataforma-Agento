<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Puntaje final = media ponderada de ATS, psicometría y entrevistas.
 *
 * - Un componente "no_aplica" (o con peso 0) se excluye y su peso se
 *   redistribuye proporcionalmente entre los aplicables:
 *   peso_efectivo_i = peso_i / Σ pesos aplicables × 100.
 * - Un componente "pendiente" NUNCA se convierte en 0: el puntaje se calcula
 *   con los componentes completos (renormalizando sus pesos efectivos) y se
 *   marca como provisional hasta que todos los aplicables estén completos.
 * - Sin componentes completos el puntaje queda sin calcular.
 */
final class CalculadoraPuntajeFinal
{
    public const COMPONENTES = ['ats', 'psicometria', 'entrevistas'];

    /**
     * @param  array<string, int|float>  $pesos  ['ats' => 40, 'psicometria' => 30, 'entrevistas' => 30]
     * @param  array<string, array{estado: string, valor: float|null}>  $componentes  estado: completo|pendiente|no_aplica
     * @return array{puntaje: float|null, provisional: bool, pesos_efectivos: array<string, float>, componentes: array<string, mixed>}
     */
    public function calcular(array $pesos, array $componentes): array
    {
        $aplicables = [];
        foreach (self::COMPONENTES as $clave) {
            $estado = $componentes[$clave]['estado'] ?? 'pendiente';
            if ($estado !== 'no_aplica' && (float) ($pesos[$clave] ?? 0) > 0) {
                $aplicables[$clave] = (float) $pesos[$clave];
            }
        }

        $totalAplicable = array_sum($aplicables);
        $pesosEfectivos = [];
        foreach ($aplicables as $clave => $peso) {
            $pesosEfectivos[$clave] = round($peso / $totalAplicable * 100, 4);
        }

        $suma = 0.0;
        $pesoCompleto = 0.0;
        $hayPendientes = false;

        foreach ($pesosEfectivos as $clave => $peso) {
            $componente = $componentes[$clave] ?? ['estado' => 'pendiente', 'valor' => null];
            if ($componente['estado'] === 'completo' && $componente['valor'] !== null) {
                $suma += $peso * (float) $componente['valor'];
                $pesoCompleto += $peso;
            } else {
                $hayPendientes = true;
            }
        }

        return [
            'puntaje' => $pesoCompleto > 0 ? round($suma / $pesoCompleto, 2) : null,
            'provisional' => $hayPendientes || $pesosEfectivos === [],
            'pesos_efectivos' => $pesosEfectivos,
            'componentes' => $componentes,
        ];
    }

    /**
     * Normalización de entrevistas (escala 1–5 → 0–100):
     * ((promedio − 1) / 4) × 100.
     *
     * @param  array<int, int>  $calificaciones
     * @return array{promedio: float, puntaje: float}
     */
    public static function normalizarEntrevista(array $calificaciones): array
    {
        if ($calificaciones === []) {
            throw new \InvalidArgumentException('Se requiere al menos una calificación.');
        }

        foreach ($calificaciones as $calificacion) {
            if ($calificacion < 1 || $calificacion > 5) {
                throw new \InvalidArgumentException('Las calificaciones deben estar entre 1 y 5.');
            }
        }

        $promedio = array_sum($calificaciones) / count($calificaciones);

        return ['promedio' => round($promedio, 2), 'puntaje' => round(($promedio - 1) / 4 * 100, 2)];
    }
}
