<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Interpretación de un intento según la guía de corrección de la prueba.
 *
 * - Nivel por dimensión a partir del puntaje bruto (suma de sus
 *   afirmaciones ya corregidas): bajo < nivel_medio_desde ≤ medio <
 *   nivel_alto_desde ≤ alto. Sin umbrales configurados no hay nivel.
 * - Escala de validez: no es un rasgo; si su suma alcanza alerta_desde, el
 *   perfil se interpreta con cautela.
 * - Filtro del proceso: cada dimensión prioritaria debe alcanzar el nivel
 *   mínimo. Es una marca para RR. HH., no un descarte automático (la prueba
 *   es de tamizaje y no debe ser el único criterio de decisión).
 */
final class PerfilPsicometrico
{
    public const NIVELES = ['bajo', 'medio', 'alto'];

    /**
     * @param  array<int, array{id: int, codigo: ?string, es_validez: bool, nivel_medio_desde: ?float, nivel_alto_desde: ?float, alerta_desde: ?float}>  $dimensiones
     * @param  array<int, float|null>  $sumas  dimension_id => suma
     * @param  array<int, string>  $prioritarias  códigos de dimensión
     * @return array{niveles: array<int, string|null>, validez: array{dimension_id: int, suma: float, alerta: bool}|null, filtro: array{cumple: bool, incumplidas: array<int, string>, sin_resultado: array<int, string>}|null}
     */
    public static function interpretar(array $dimensiones, array $sumas, array $prioritarias = [], ?string $nivelMinimo = null): array
    {
        $niveles = [];
        $validez = null;
        $porCodigo = [];

        foreach ($dimensiones as $dimension) {
            $suma = $sumas[$dimension['id']] ?? null;

            if ($dimension['es_validez']) {
                if ($suma !== null && $validez === null) {
                    $validez = [
                        'dimension_id' => $dimension['id'],
                        'suma' => (float) $suma,
                        'alerta' => $dimension['alerta_desde'] !== null && $suma >= $dimension['alerta_desde'],
                    ];
                }

                continue;
            }

            $nivel = self::nivel($suma, $dimension['nivel_medio_desde'], $dimension['nivel_alto_desde']);
            $niveles[$dimension['id']] = $nivel;
            if ($dimension['codigo'] !== null) {
                $porCodigo[$dimension['codigo']] = $nivel;
            }
        }

        $filtro = null;
        if ($prioritarias !== [] && in_array($nivelMinimo, self::NIVELES, true)) {
            $minimo = array_search($nivelMinimo, self::NIVELES, true);
            $incumplidas = [];
            $sinResultado = [];
            foreach ($prioritarias as $codigo) {
                $nivel = $porCodigo[$codigo] ?? null;
                if ($nivel === null) {
                    $sinResultado[] = $codigo;
                } elseif (array_search($nivel, self::NIVELES, true) < $minimo) {
                    $incumplidas[] = $codigo;
                }
            }
            $filtro = ['cumple' => $incumplidas === [] && $sinResultado === [], 'incumplidas' => $incumplidas, 'sin_resultado' => $sinResultado];
        }

        return ['niveles' => $niveles, 'validez' => $validez, 'filtro' => $filtro];
    }

    public static function nivel(?float $suma, ?float $medioDesde, ?float $altoDesde): ?string
    {
        if ($suma === null || $medioDesde === null || $altoDesde === null) {
            return null;
        }

        return match (true) {
            $suma >= $altoDesde => 'alto',
            $suma >= $medioDesde => 'medio',
            default => 'bajo',
        };
    }
}
