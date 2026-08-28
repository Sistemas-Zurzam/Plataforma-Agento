<?php

namespace App\Modules\Nominas\Application\AfpNet;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Nominas\Domain\AfpNet\AfpNetExportContext;
use App\Modules\Nominas\Domain\AfpNet\AfpNetMapeoLookup;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
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
