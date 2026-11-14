<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Calificación de un intento por dimensión:
 *   puntaje_dimensión = Σ(valor_opción_elegida × peso_pregunta)
 *                       / Σ(valor_máximo_de_la_pregunta × peso_pregunta) × 100
 * Una pregunta sin responder al cerrar el intento aporta 0 al numerador (el
 * intento terminó; no es un componente pendiente).
 *   puntaje_total = Σ(puntaje_dimensión × peso_dimensión) / Σ peso_dimensión
 * Una dimensión con peso 0 (escala de validez) no suma al total.
 */
final class CalificadorPrueba
{
    /**
     * @param  array<int, array{id: int, peso: int}>  $dimensiones
     * @param  array<int, array{id: int, dimension_id: int, peso: int, opciones: array<int, array{id: int, valor: float}>}>  $preguntas
     * @param  array<int, int>  $respuestas  pregunta_id => opcion_id
     * @return array{puntaje: float|null, dimensiones: array<int, float>, sumas: array<int, float>}
     */
    public function calificar(array $dimensiones, array $preguntas, array $respuestas): array
    {
        $numerador = [];
        $denominador = [];

        foreach ($preguntas as $pregunta) {
            $valores = array_column($pregunta['opciones'], 'valor', 'id');
            $maximo = $valores === [] ? 0.0 : max($valores);
            if ($maximo <= 0) {
                continue;
            }

            $dimension = $pregunta['dimension_id'];
            $elegida = $respuestas[$pregunta['id']] ?? null;
            $valor = $elegida !== null && isset($valores[$elegida]) ? (float) $valores[$elegida] : 0.0;

            $numerador[$dimension] = ($numerador[$dimension] ?? 0) + $valor * $pregunta['peso'];
            $denominador[$dimension] = ($denominador[$dimension] ?? 0) + $maximo * $pregunta['peso'];
        }

        $porDimension = [];
        $sumas = [];
        $sumaPonderada = 0.0;
        $sumaPesos = 0.0;

        foreach ($dimensiones as $dimension) {
            if (empty($denominador[$dimension['id']])) {
                continue;
            }
            $puntaje = round($numerador[$dimension['id']] / $denominador[$dimension['id']] * 100, 2);
            $porDimension[$dimension['id']] = $puntaje;
            // Puntaje bruto (Σ valor × peso): el que usan las guías de
            // corrección para los niveles (ej. 5–25 con 5 afirmaciones 1–5).
            $sumas[$dimension['id']] = round((float) $numerador[$dimension['id']], 2);
            $sumaPonderada += $puntaje * $dimension['peso'];
            $sumaPesos += $dimension['peso'];
        }

        return [
            'puntaje' => $sumaPesos > 0 ? round($sumaPonderada / $sumaPesos, 2) : null,
            'dimensiones' => $porDimension,
            'sumas' => $sumas,
        ];
    }
}
