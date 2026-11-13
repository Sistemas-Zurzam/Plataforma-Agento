<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Perfil inicial de un proceso a partir de lo que la empresa describió en su
 * solicitud. Es solo una propuesta: RR. HH. la revisa y la guarda como
 * versión del perfil.
 *
 * - Requisitos: una línea del texto = un requisito (sin viñetas ni
 *   numeración). Es "excluyente" solo si la empresa lo marcó como
 *   indispensable/obligatorio/excluyente; si no, "deseable", porque un
 *   excluyente incumplido deja el ATS en 0.
 * - Competencias: las habilidades declaradas con su nivel; los pesos se
 *   reparten en partes iguales hasta sumar 100.
 */
final class PerfilSugerido
{
    public const MAX_REQUISITOS = 30;

    private const MAX_LONGITUD = 300;

    private const MARCAS_EXCLUYENTE = '/\b(indispensable|imprescindible|excluyente|obligatori[oa]s?)\b/iu';

    /**
     * @return array<int, array{tipo: string, descripcion: string}>
     */
    public static function requisitos(?string $texto): array
    {
        return collect(preg_split('/\R/u', (string) $texto))
            ->map(fn (string $linea) => trim(preg_replace('/^\s*(?:[-•*·▪●]|\d+[.)])\s*/u', '', $linea)))
            ->filter(fn (string $linea) => $linea !== '')
            ->unique(fn (string $linea) => mb_strtolower($linea))
            ->take(self::MAX_REQUISITOS)
            ->map(fn (string $linea) => [
                'tipo' => preg_match(self::MARCAS_EXCLUYENTE, $linea) ? 'excluyente' : 'deseable',
                'descripcion' => mb_substr($linea, 0, self::MAX_LONGITUD),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  iterable<array{nombre: string, nivel: int|string}>  $habilidades
     * @return array<int, array{nombre: string, nivel: int, peso: int}>
     */
    public static function competencias(iterable $habilidades): array
    {
        $lista = collect($habilidades)->values();
        $total = $lista->count();
        if ($total === 0) {
            return [];
        }

        $base = intdiv(100, $total);
        $resto = 100 - $base * $total;

        return $lista->map(fn ($h, int $i) => [
            'nombre' => (string) $h['nombre'],
            'nivel' => (int) $h['nivel'],
            'peso' => $base + ($i < $resto ? 1 : 0),
        ])->all();
    }
}
