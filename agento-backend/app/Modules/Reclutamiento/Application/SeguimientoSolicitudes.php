<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;

/**
 * Seguimiento AGREGADO por solicitud para quien solicitó personal (GER):
 * solo conteos por etapa y avance de plazas del proceso real — nunca datos
 * de candidatos, puntajes, notas ni resultados de evaluaciones.
 *
 * Mapeo de etapas reales (FlujoPostulacion) a las del seguimiento:
 *   postulacion → postulaciones; filtro_cv, psicometrica → evaluaciones;
 *   entrevista_virtual; entrevista_presencial; seleccion → seleccion;
 *   plazas cubiertas → cubierto. Solo cuentan postulaciones vigentes
 *   (activa o seleccionada).
 */
class SeguimientoSolicitudes
{
    private const MAPA_ETAPAS = [
        'postulacion' => 'postulaciones',
        'filtro_cv' => 'evaluaciones',
        'psicometrica' => 'evaluaciones',
        'entrevista_virtual' => 'entrevista_virtual',
        'entrevista_presencial' => 'entrevista_presencial',
        'seleccion' => 'seleccion',
    ];

    /**
     * @param  array<int, int>  $solicitudIds
     * @return array<int, array<string, mixed>> indexado por solicitud_id
     */
    public function porSolicitudes(int $empresaId, array $solicitudIds): array
    {
        if ($solicitudIds === []) {
            return [];
        }

        $procesos = ProcesoSeleccion::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('solicitud_id', $solicitudIds)
            ->get(['id', 'solicitud_id', 'codigo', 'estado', 'cubierto_at']);
        $procesoIds = $procesos->pluck('id');

        $plazas = Plaza::query()->whereIn('proceso_id', $procesoIds)
            ->selectRaw('proceso_id, estado, count(*) as total')
            ->groupBy('proceso_id', 'estado')->get()
            ->groupBy('proceso_id');

        $etapas = Postulacion::query()->whereIn('proceso_id', $procesoIds)
            ->whereIn('estado', ['activa', 'seleccionada'])
            ->selectRaw('proceso_id, etapa, count(*) as total')
            ->groupBy('proceso_id', 'etapa')->get()
            ->groupBy('proceso_id');

        $resultado = [];
        foreach ($procesos as $proceso) {
            $porEstado = ($plazas->get($proceso->id) ?? collect())->pluck('total', 'estado');
            $conteos = array_fill_keys(['postulaciones', 'evaluaciones', 'entrevista_virtual', 'entrevista_presencial', 'seleccion'], 0);
            foreach ($etapas->get($proceso->id) ?? [] as $fila) {
                $clave = self::MAPA_ETAPAS[$fila->etapa] ?? null;
                if ($clave) {
                    $conteos[$clave] += (int) $fila->total;
                }
            }
            $cubiertas = (int) ($porEstado[Plaza::CUBIERTA] ?? 0);
            $vigentes = $cubiertas + (int) ($porEstado[Plaza::DISPONIBLE] ?? 0);

            $resultado[$proceso->solicitud_id] = [
                'proceso' => ['codigo' => $proceso->codigo, 'estado' => $proceso->estado],
                'plazas_vigentes' => $vigentes,
                'plazas_cubiertas' => $cubiertas,
                'plazas_canceladas' => (int) ($porEstado[Plaza::CANCELADA] ?? 0),
                // Cubierto solo cuando TODAS las plazas vigentes lo están.
                'cubierto' => $vigentes > 0 && $cubiertas === $vigentes,
                'etapas' => $conteos + ['cubierto' => $cubiertas],
            ];
        }

        return $resultado;
    }
}
