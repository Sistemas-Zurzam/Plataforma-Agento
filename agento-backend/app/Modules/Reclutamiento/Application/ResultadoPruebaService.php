<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\PerfilPsicometrico;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;

/**
 * Hoja de resultados de una prueba rendida, para RR. HH.: puntaje bruto y
 * nivel por dimensión, interpretación y pregunta de entrevista de la guía,
 * escala de validez y resultado del filtro del proceso. Se calcula al leer
 * (no se guarda): si cambia el filtro del proceso, el resultado se actualiza.
 *
 * Espera cargadas: prueba.dimensiones e intento.resultados (+ concluidoPor).
 */
class ResultadoPruebaService
{
    /**
     * Resumen para la tarjeta del tablero: estado de una prueba configurada
     * en el proceso (pendiente / cumple / no cumple / rendida) y alerta de
     * validez. Sin $familia: la psicométrica.
     *
     * @param  iterable<PruebaAsignacion>  $asignaciones
     * @return array{estado: string, alerta_validez: bool}|null
     */
    public function resumen(iterable $asignaciones, ProcesoSeleccion $proceso, ?string $familia = null): ?array
    {
        $familia ??= $proceso->prueba_familia;
        if (! $familia) {
            return null;
        }

        $asignacion = collect($asignaciones)
            ->filter(fn ($a) => $a->prueba?->familia === $familia && $a->estado !== PruebaAsignacion::ANULADA)
            ->sortByDesc('id')
            ->first();
        if (! $asignacion) {
            return null;
        }
        if ($asignacion->estado !== PruebaAsignacion::FINALIZADA) {
            return ['estado' => in_array($asignacion->estado, [PruebaAsignacion::PENDIENTE, PruebaAsignacion::EN_CURSO], true) ? 'pendiente' : $asignacion->estado, 'alerta_validez' => false];
        }

        $hoja = $this->hoja($asignacion, $proceso);

        return [
            'estado' => match ($hoja['filtro']['cumple'] ?? null) {
                true => 'cumple',
                false => 'no_cumple',
                default => 'rendida',
            },
            'alerta_validez' => (bool) ($hoja['validez']['alerta'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function hoja(PruebaAsignacion $asignacion, ?ProcesoSeleccion $proceso): ?array
    {
        $intento = $asignacion->intento;
        if (! $intento?->finalizado_at) {
            return null;
        }

        $dimensiones = $asignacion->prueba->dimensiones;
        $resultados = $intento->resultados->keyBy('dimension_id');
        $aplicaFiltro = $proceso && $proceso->prueba_familia === $asignacion->prueba->familia;
        $prioritarias = $aplicaFiltro ? $proceso->dimensionesPrioritarias->pluck('codigo')->all() : [];

        $interpretacion = PerfilPsicometrico::interpretar(
            $dimensiones->map(fn ($d) => [
                'id' => $d->id,
                'codigo' => $d->codigo,
                'es_validez' => $d->es_validez,
                'nivel_medio_desde' => $d->nivel_medio_desde,
                'nivel_alto_desde' => $d->nivel_alto_desde,
                'alerta_desde' => $d->alerta_desde,
            ])->all(),
            $resultados->map(fn ($r) => $r->suma)->all(),
            $prioritarias,
            $aplicaFiltro ? $proceso->prueba_nivel_minimo : null,
        );

        $nombres = $dimensiones->whereNotNull('codigo')->pluck('nombre', 'codigo');
        $validez = $interpretacion['validez'];
        $filtro = $interpretacion['filtro'];

        // Conocimientos: el filtro es el puntaje mínimo (0–100) del proceso.
        $filtroConocimientos = null;
        if ($proceso && $proceso->conocimientos_familia === $asignacion->prueba->familia && $proceso->conocimientos_puntaje_minimo !== null) {
            $filtroConocimientos = [
                'cumple' => $intento->puntaje !== null && $intento->puntaje >= $proceso->conocimientos_puntaje_minimo,
                'puntaje' => $intento->puntaje,
                'puntaje_minimo' => $proceso->conocimientos_puntaje_minimo,
            ];
        }

        return [
            'dimensiones' => $dimensiones->where('es_validez', false)->map(function ($d) use ($resultados, $interpretacion, $prioritarias) {
                $nivel = $interpretacion['niveles'][$d->id] ?? null;

                return [
                    'codigo' => $d->codigo,
                    'nombre' => $d->nombre,
                    'puntaje' => $resultados[$d->id]->puntaje ?? null,
                    'suma' => $resultados[$d->id]->suma ?? null,
                    'nivel' => $nivel,
                    'prioritaria' => $d->codigo !== null && in_array($d->codigo, $prioritarias, true),
                    'interpretacion' => match ($nivel) {
                        'alto' => $d->interpretacion_alta,
                        'bajo' => $d->interpretacion_baja,
                        default => null,
                    },
                    'pregunta_entrevista' => $d->pregunta_entrevista,
                ];
            })->values()->all(),
            'validez' => $validez ? [
                'nombre' => $dimensiones->firstWhere('id', $validez['dimension_id'])?->nombre,
                'suma' => $validez['suma'],
                'alerta' => $validez['alerta'],
                'alerta_desde' => $dimensiones->firstWhere('id', $validez['dimension_id'])?->alerta_desde,
            ] : null,
            'filtro' => $filtroConocimientos ?? ($filtro ? [
                'cumple' => $filtro['cumple'],
                'nivel_minimo' => $proceso->prueba_nivel_minimo,
                'incumplidas' => collect($filtro['incumplidas'])->map(fn ($c) => $nombres[$c] ?? $c)->values()->all(),
                'sin_resultado' => collect($filtro['sin_resultado'])->map(fn ($c) => $nombres[$c] ?? $c)->values()->all(),
            ] : null),
            'conclusion' => $intento->conclusion ? [
                'valor' => $intento->conclusion,
                'comentario' => $intento->conclusion_comentario,
                'por' => $intento->concluidoPor?->name,
                'at' => $intento->concluido_at?->toIso8601String(),
            ] : null,
        ];
    }
}
