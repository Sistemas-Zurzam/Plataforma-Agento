<?php

namespace App\Modules\Nominas\Application\ReporteEjecutivo;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use Illuminate\Support\Collection;

/**
 * Conceptos que realmente se pagaron por boleta: los de la boleta original
 * o, si tiene una planilla complementaria aprobada/pagada, los del
 * recálculo completo guardado en esa complementaria. Mismo criterio que
 * PlameCicloDatosLoader::aplicarComplementariasAprobadas() — el reintegro
 * queda repartido en los conceptos que corresponden (HE, bonos, descuentos
 * devueltos…) en lugar de una columna "Reintegros" aparte. La boleta
 * original nunca se modifica.
 */
final class ConceptosEfectivosPorBoleta
{
    /**
     * @param  Collection<int, Boleta>  $boletas  Con `conceptos.concepto` y `conceptos.conceptoDefinicion` precargados.
     * @return Collection<int, Collection<int, array{tipo: string, codigo: string, nombre: string, definicion_id: ?int, definicion: ?string, monto: float}>>
     *   indexada por boleta_id
     */
    public static function resolver(Collection $boletas): Collection
    {
        $snapshots = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletas->pluck('id'))
            ->whereHas('complementaria', fn ($query) => $query->whereIn('estado', ['aprobada', 'pagada']))
            ->latest('id')
            ->get(['id', 'boleta_original_id', 'calculo_snapshot'])
            ->unique('boleta_original_id')
            ->mapWithKeys(fn (PlanillaComplementariaDetalle $d) => [$d->boleta_original_id => $d->calculo_snapshot ?? []]);
        $desdeSnapshot = self::desdeResultados($snapshots);

        return $boletas->mapWithKeys(fn (Boleta $boleta) => [
            $boleta->id => $desdeSnapshot->get($boleta->id) ?? $boleta->conceptos->map(fn (BoletaConcepto $c) => [
                'tipo' => (string) $c->tipo,
                'codigo' => (string) $c->concepto?->codigo,
                'nombre' => (string) ($c->concepto?->nombre ?? $c->concepto?->codigo),
                'definicion_id' => $c->concepto_definicion_id,
                'definicion' => $c->conceptoDefinicion?->nombre,
                'monto' => round((float) $c->monto, 2),
            ])->values(),
        ]);
    }

    /**
     * Normaliza resultados del motor de cálculo (forma de
     * CalcularBoletaColaborador::calcular(): ingresos/egresos/aportaciones
     * con `codigo`, `monto` y `concepto_definicion_id`) — sea el snapshot
     * de una complementaria o un cálculo en memoria (Proyectado de planilla).
     *
     * @param  Collection<int|string, array<string, mixed>>  $resultados  Indexados por la clave que se quiera conservar.
     * @return Collection<int|string, Collection<int, array{tipo: string, codigo: string, nombre: string, definicion_id: ?int, definicion: ?string, monto: float}>>
     */
    public static function desdeResultados(Collection $resultados): Collection
    {
        $lineas = $resultados->flatMap(fn (array $r) => [...($r['ingresos'] ?? []), ...($r['egresos'] ?? []), ...($r['aportaciones'] ?? [])]);
        $nombres = $lineas->isEmpty() ? collect()
            : ConceptoRemuneracion::whereIn('codigo', $lineas->pluck('codigo')->unique())->pluck('nombre', 'codigo');
        $definicionIds = $lineas->pluck('concepto_definicion_id')->filter()->unique();
        $definiciones = $definicionIds->isEmpty() ? collect()
            : ConceptoDefinicionPlame::whereIn('id', $definicionIds)->pluck('nombre', 'id');

        return $resultados->map(fn (array $resultado) => collect(['ingreso' => 'ingresos', 'egreso' => 'egresos', 'aportacion' => 'aportaciones'])
            ->flatMap(fn (string $clave, string $tipo) => collect($resultado[$clave] ?? [])->map(fn (array $linea) => [
                'tipo' => $tipo,
                'codigo' => (string) $linea['codigo'],
                'nombre' => (string) ($nombres->get($linea['codigo']) ?? $linea['codigo']),
                'definicion_id' => $linea['concepto_definicion_id'] ?? null,
                'definicion' => $definiciones->get($linea['concepto_definicion_id'] ?? null),
                'monto' => round((float) ($linea['monto'] ?? 0), 2),
            ]))
            ->values());
    }
}
