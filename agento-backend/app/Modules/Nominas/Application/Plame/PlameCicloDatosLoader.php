<?php

namespace App\Modules\Nominas\Application\Plame;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Collection;

/**
 * Único lugar que sabe CÓMO cargar las boletas/condiciones de un ciclo para
 * PLAME — compartido por PlameValidator y PlameExportService (Sección 68:
 * ambos deben leer EXACTAMENTE los mismos datos, con los mismos filtros y
 * eager loads; si divergieran, un ciclo podría validarse "listo" contra un
 * conjunto de boletas distinto al que realmente se exporta).
 */
final class PlameCicloDatosLoader
{
    public static function boletasPlanilla(CicloRemunerativo $ciclo): Collection
    {
        $boletas = Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')
            ->with(['colaborador', 'conceptos.concepto'])
            ->get();

        self::aplicarComplementariasAprobadas($ciclo, $boletas);

        return $boletas;
    }

    /**
     * Un colaborador con una planilla complementaria aprobada/pagada para
     * este ciclo debe declararse en .rem por su monto YA CORREGIDO — la
     * boleta original nunca se modifica (regla de auditoría/historial), así
     * que el reemplazo ocurre únicamente en memoria, solo para esta lectura
     * PLAME. Se hace acá (no en PlameExportService) para que PlameValidator
     * vea EXACTAMENTE los mismos conceptos que luego se exportan (Sección
     * 68) — nunca una boleta "lista" en la validación y distinta en la
     * exportación real.
     *
     * Deliberadamente NO se declara como una línea adicional/delta: se
     * RECONSTRUYE por completo el detalle de conceptos del colaborador a
     * partir del recálculo ya guardado en la complementaria
     * (`calculo_snapshot`, la misma forma que produce
     * CalcularBoletaColaborador::calcular()) — sumar la diferencia encima de
     * los conceptos originales duplicaría montos que ya están incluidos en
     * ese recálculo completo.
     *
     * Alcance actual: solo planilla dependiente (nunca Locación de
     * Servicios) — un ajuste a un recibo por honorarios requiere un
     * comprobante RH propio (serie/número), no una línea de concepto, así
     * que queda fuera de esta reconstrucción.
     */
    private static function aplicarComplementariasAprobadas(CicloRemunerativo $ciclo, Collection $boletas): void
    {
        $detalles = PlanillaComplementariaDetalle::whereHas(
            'complementaria',
            fn ($q) => $q->where('ciclo_id', $ciclo->id)->whereIn('estado', ['aprobada', 'pagada']),
        )
            ->where('calculo_snapshot->regimen_laboral', '!=', 'Locacion de Servicios')
            ->latest('id')
            ->get()
            ->unique('colaborador_id');

        if ($detalles->isEmpty()) {
            return;
        }

        $lineas = $detalles->flatMap(fn (PlanillaComplementariaDetalle $d) => collect([
            ...($d->calculo_snapshot['ingresos'] ?? []),
            ...($d->calculo_snapshot['egresos'] ?? []),
            ...($d->calculo_snapshot['aportaciones'] ?? []),
        ]));
        $codigos = $lineas->pluck('codigo')->unique();
        $conceptos = ConceptoRemuneracion::whereIn('codigo', $codigos)->get()->keyBy('codigo');
        $definicionIds = $lineas->pluck('concepto_definicion_id')->filter()->unique();
        $definiciones = $definicionIds->isEmpty() ? collect() : ConceptoDefinicionPlame::whereIn('id', $definicionIds)->get()->keyBy('id');

        $detallesPorColaborador = $detalles->keyBy('colaborador_id');

        foreach ($boletas as $boleta) {
            /** @var PlanillaComplementariaDetalle|null $detalle */
            $detalle = $detallesPorColaborador->get($boleta->colaborador_id);
            if (! $detalle) {
                continue;
            }

            $snapshot = $detalle->calculo_snapshot;
            $conceptosReemplazo = collect([
                ...collect($snapshot['ingresos'] ?? [])->map(fn (array $l) => [...$l, '_tipo' => 'ingreso']),
                ...collect($snapshot['egresos'] ?? [])->map(fn (array $l) => [...$l, '_tipo' => 'egreso']),
                ...collect($snapshot['aportaciones'] ?? [])->map(fn (array $l) => [...$l, '_tipo' => 'aportacion']),
            ])->map(function (array $linea) use ($conceptos, $definiciones) {
                $concepto = $conceptos->get($linea['codigo']);
                if (! $concepto) {
                    return null; // catálogo incompleto para este código — se omite la línea, igual que BoletaService
                }

                $definicion = $definiciones->get($linea['concepto_definicion_id'] ?? null);

                $boletaConcepto = new BoletaConcepto([
                    'tipo' => $linea['_tipo'],
                    'es_remunerativo_laboral' => $concepto->es_remunerativo_laboral,
                    'afecta_renta_5ta' => $concepto->afecta_renta_5ta,
                    'codigo_plame_snapshot' => $definicion?->codigo_plame ?? $concepto->codigo_plame,
                    'base_utilizada' => $linea['base_utilizada'] ?? null,
                    'tasa_aplicada' => $linea['tasa_aplicada'] ?? null,
                    'cantidad' => $linea['cantidad'] ?? null,
                    'monto' => $linea['monto'],
                    'monto_devengado' => $linea['monto'],
                    'monto_pagado_descontado' => $linea['monto'],
                    'formula_texto' => $linea['formula_texto'] ?? null,
                ]);
                $boletaConcepto->setRelation('concepto', $concepto);

                return $boletaConcepto;
            })->filter()->values();

            $boleta->setRelation('conceptos', $conceptosReemplazo);
        }
    }

    public static function boletasRh(CicloRemunerativo $ciclo): Collection
    {
        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '=', 'Locacion de Servicios')
            ->with(['colaborador', 'conceptos.concepto', 'comprobanteRh'])
            ->get();
    }

    /**
     * @return Collection<int, Collection<int, ColaboradorCondicionLaboral>>
     */
    public static function condicionesPorColaborador(Collection $colaboradorIds): Collection
    {
        return ColaboradorCondicionLaboral::whereIn('colaborador_id', $colaboradorIds)
            ->orderByDesc('vigencia_desde')->orderByDesc('id')
            ->get()
            ->groupBy('colaborador_id');
    }
}
