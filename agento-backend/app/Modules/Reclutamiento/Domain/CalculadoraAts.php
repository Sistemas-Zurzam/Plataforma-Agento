<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Fórmula ATS "ats-v1" — compatibilidad entre la candidatura y el perfil.
 *
 * Entradas (evidencia ya resuelta, venga de reglas, de un proveedor o de una
 * evaluación manual):
 * - requisitos: [{id, tipo: excluyente|deseable, descripcion, resultado: cumple|no_cumple|sin_informacion}]
 * - competencias: [{nombre, nivel_requerido 1-5, peso, nivel_candidato: 1-5|null}]
 *
 * Reglas:
 * 1. Un excluyente confirmado como "no_cumple" ⇒ puntaje 0, resultado no_apto.
 * 2. Cobertura de competencias = Σ(peso × min(nivel_candidato / nivel_requerido, 1))
 *    / Σ(peso de las competencias con información). Una competencia sin
 *    información NO cuenta como 0: se excluye del cálculo y marca revisión.
 * 3. Deseables: aportan como máximo 10 puntos = 10 × cumplidos / total de
 *    deseables. Sin deseables definidos, la cobertura vale los 100 puntos.
 *    puntaje = 90 × cobertura + aporte_deseables  (o 100 × cobertura).
 * 4. Si falta información en algún excluyente o competencia ⇒ requiere_revision
 *    (nunca se asume incumplimiento). Si no hay ninguna competencia con
 *    información, el puntaje queda sin calcular.
 * 5. Con información completa: apto si puntaje ≥ umbral; si no, bajo_umbral.
 *
 * El resultado es una recomendación: el descarte siempre es decisión humana.
 */
final class CalculadoraAts
{
    public const FORMULA = 'ats-v1';

    public const APORTE_MAXIMO_DESEABLES = 10;

    /**
     * @param  array<int, array{id?: int, tipo: string, descripcion?: string, resultado: string}>  $requisitos
     * @param  array<int, array{nombre: string, nivel_requerido: int, peso: int|float, nivel_candidato: int|null}>  $competencias
     * @return array{puntaje: float|null, resultado: string, desglose: array<string, mixed>}
     */
    public function calcular(array $requisitos, array $competencias, int $umbral): array
    {
        $excluyentes = array_values(array_filter($requisitos, fn (array $r) => $r['tipo'] === 'excluyente'));
        $deseables = array_values(array_filter($requisitos, fn (array $r) => $r['tipo'] === 'deseable'));

        $incumplidos = array_values(array_filter($excluyentes, fn (array $r) => $r['resultado'] === 'no_cumple'));
        $excluyentesSinInfo = array_values(array_filter($excluyentes, fn (array $r) => $r['resultado'] === 'sin_informacion'));

        $detalleCompetencias = [];
        $pesoEvaluado = 0.0;
        $sumaPonderada = 0.0;
        $competenciasSinInfo = 0;

        foreach ($competencias as $competencia) {
            $requerido = max(1, (int) $competencia['nivel_requerido']);
            $nivel = $competencia['nivel_candidato'];
            $cobertura = $nivel === null ? null : min($nivel / $requerido, 1.0);

            if ($cobertura === null) {
                $competenciasSinInfo++;
            } else {
                $pesoEvaluado += (float) $competencia['peso'];
                $sumaPonderada += (float) $competencia['peso'] * $cobertura;
            }

            $detalleCompetencias[] = [
                'nombre' => $competencia['nombre'],
                'nivel_requerido' => $requerido,
                'nivel_candidato' => $nivel,
                'peso' => $competencia['peso'],
                'cobertura' => $cobertura === null ? null : round($cobertura, 4),
            ];
        }

        $deseablesCumplidos = count(array_filter($deseables, fn (array $r) => $r['resultado'] === 'cumple'));
        $aporteDeseables = $deseables === [] ? 0.0 : self::APORTE_MAXIMO_DESEABLES * $deseablesCumplidos / count($deseables);
        $escalaCompetencias = $deseables === [] ? 100 : 100 - self::APORTE_MAXIMO_DESEABLES;
        $cobertura = $pesoEvaluado > 0 ? $sumaPonderada / $pesoEvaluado : null;

        $desglose = [
            'formula' => self::FORMULA,
            'umbral' => $umbral,
            'excluyentes' => $excluyentes,
            'deseables' => $deseables,
            'competencias' => $detalleCompetencias,
            'cobertura_competencias' => $cobertura === null ? null : round($cobertura, 4),
            'escala_competencias' => $escalaCompetencias,
            'aporte_deseables' => round($aporteDeseables, 2),
            'faltan_datos' => $excluyentesSinInfo !== [] || $competenciasSinInfo > 0,
        ];

        if ($incumplidos !== []) {
            return ['puntaje' => 0.0, 'resultado' => 'no_apto', 'desglose' => [...$desglose, 'motivo' => 'Excluyente incumplido']];
        }

        if ($cobertura === null) {
            return ['puntaje' => null, 'resultado' => 'requiere_revision', 'desglose' => [...$desglose, 'motivo' => 'Sin información de competencias']];
        }

        $puntaje = round(min(100.0, max(0.0, $escalaCompetencias * $cobertura + $aporteDeseables)), 2);

        if ($desglose['faltan_datos']) {
            return ['puntaje' => $puntaje, 'resultado' => 'requiere_revision', 'desglose' => [...$desglose, 'motivo' => 'Información insuficiente']];
        }

        return [
            'puntaje' => $puntaje,
            'resultado' => $puntaje >= $umbral ? 'apto' : 'bajo_umbral',
            'desglose' => $desglose,
        ];
    }

    /**
     * Normaliza nombres de habilidades para compararlas (minúsculas, sin
     * tildes ni espacios repetidos).
     */
    public static function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/\s+/', ' ', $sinTildes);
    }
}
