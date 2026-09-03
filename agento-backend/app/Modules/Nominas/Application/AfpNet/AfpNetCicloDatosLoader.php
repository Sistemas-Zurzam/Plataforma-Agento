<?php

namespace App\Modules\Nominas\Application\AfpNet;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Nominas\Domain\AfpNet\AfpNetExportContext;
use App\Modules\Nominas\Domain\AfpNet\AfpNetMapeoLookup;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Collection;

/**
 * Único lugar que sabe CÓMO cargar los datos de un ciclo para AFPnet —
 * compartido por AfpNetValidator y AfpNetExportService (Sección 7 del
 * encargo: ambos deben leer EXACTAMENTE los mismos datos). Deliberadamente
 * NO reutiliza el loader de PLAME (AFPnet completamente separado).
 */
final class AfpNetCicloDatosLoader
{
    /** Sistemas previsionales SPP — únicos elegibles para AFPnet (Sección 6/27). */
    private const CLAVES_AFP = ['prima', 'profuturo', 'integra', 'habitat'];

    public static function cargar(CicloRemunerativo $ciclo): AfpNetExportContext
    {
        $boletas = Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')
            ->with(['colaborador', 'conceptos.concepto'])
            ->get();

        $colaboradorIds = $boletas->pluck('colaborador_id')->unique();

        $condicionesPorColaborador = ColaboradorCondicionLaboral::whereIn('colaborador_id', $colaboradorIds)
            ->orderByDesc('vigencia_desde')->orderByDesc('id')
            ->get()
            ->groupBy('colaborador_id');

        $fechaResolucion = $ciclo->fecha_fin->toDateString();
        $boletasAfp = $boletas->filter(function (Boleta $boleta) use ($condicionesPorColaborador, $fechaResolucion) {
            $condicion = self::condicionVigenteEn($condicionesPorColaborador->get($boleta->colaborador_id) ?? collect(), $fechaResolucion);
            $sistemaPrevisional = $condicion?->sistema_previsional ?? $boleta->colaborador?->sistema_previsional;

            return in_array($sistemaPrevisional, self::CLAVES_AFP, true);
        })->values();

        self::aplicarComplementariasAprobadas($ciclo, $boletasAfp);

        $permisosPorColaborador = self::cargarPermisos($boletasAfp->pluck('colaborador_id'), $ciclo)
            ->groupBy('colaborador_id');

        return new AfpNetExportContext(
            $ciclo->empresa,
            $ciclo,
            $boletasAfp,
            $condicionesPorColaborador,
            $permisosPorColaborador,
            AfpNetMapeoLookup::cargar(),
        );
    }

    /**
     * AFPnet solo declara `remuneracion_asegurable`, que es
     * `AFP_APORTE_OBLIGATORIO.base_utilizada` (ver AfpNetFilaBuilder /
     * AfpNetValidator) — nunca el monto del aporte. Si el colaborador tiene
     * una planilla complementaria aprobada/pagada para este ciclo, esa base
     * ya viene corregida en `calculo_snapshot` (PlanillaComplementariaService::
     * recalcularAfpEssalud()), así que basta con actualizar ESE único campo
     * en memoria — nunca se reconstruye toda la colección de conceptos como
     * sí hace PlameCicloDatosLoader (.rem declara cada concepto por línea;
     * AFPnet no).
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
            ->unique('colaborador_id')
            ->keyBy('colaborador_id');

        if ($detalles->isEmpty()) {
            return;
        }

        foreach ($boletas as $boleta) {
            /** @var PlanillaComplementariaDetalle|null $detalle */
            $detalle = $detalles->get($boleta->colaborador_id);
            if (! $detalle) {
                continue;
            }

            $lineaAfp = collect($detalle->calculo_snapshot['egresos'] ?? [])
                ->first(fn (array $l) => $l['codigo'] === 'AFP_APORTE_OBLIGATORIO');
            if (! $lineaAfp) {
                continue; // colaborador ONP — ya excluido por el filtro AFP de cargar(), resguardo explícito
            }

            $conceptoAfp = $boleta->conceptos->first(fn ($c) => $c->concepto?->codigo === 'AFP_APORTE_OBLIGATORIO');
            if ($conceptoAfp) {
                $conceptoAfp->base_utilizada = $lineaAfp['base_utilizada'];
            }
        }
    }

    private static function cargarPermisos(Collection $colaboradorIds, CicloRemunerativo $ciclo): Collection
    {
        if ($colaboradorIds->isEmpty()) {
            return collect();
        }

        return AsistenciaPermiso::whereIn('colaborador_id', $colaboradorIds)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $ciclo->fecha_fin)
            ->whereDate('fecha_fin', '>=', $ciclo->fecha_inicio)
            ->with('tipoAusencia')
            ->get();
    }

    private static function condicionVigenteEn(Collection $historial, string $fecha): ?ColaboradorCondicionLaboral
    {
        return $historial->first(fn (ColaboradorCondicionLaboral $c) => $c->vigencia_desde->toDateString() <= $fecha);
    }
}
