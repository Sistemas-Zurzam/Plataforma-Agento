<?php

namespace App\Modules\Nominas\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ResumenContableService
{
    /**
     * Consolida las boletas vigentes de todas las empresas autorizadas.
     * Los filtros se aplican antes de agregar para no mezclar el alcance
     * global con las tarjetas del ciclo seleccionado.
     *
     * @param  array<int, int>  $empresaIds
     */
    public function consolidar(array $empresaIds, string $periodo, ?string $estado, ?string $categoria): array
    {
        $inicio = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->toDateString();
        $fin = Carbon::createFromFormat('Y-m', $periodo)->endOfMonth()->toDateString();

        $consulta = DB::table('ciclos_remunerativos as c')
            ->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            ->leftJoin('boletas as b', function ($join) use ($categoria) {
                $join->on('b.ciclo_id', '=', 'c.id')
                    ->where('b.es_version_vigente', true);

                if ($categoria === 'honorarios') {
                    $join->where('b.regimen_laboral_snapshot', 'Locacion de Servicios');
                } elseif ($categoria === 'planilla') {
                    $join->where('b.regimen_laboral_snapshot', '!=', 'Locacion de Servicios');
                }
            })
            ->whereIn('c.empresa_id', $empresaIds)
            ->whereDate('c.fecha_inicio', '<=', $fin)
            ->whereDate('c.fecha_fin', '>=', $inicio)
            ->when($estado, fn ($query) => $query->where('c.estado', $estado))
            ->groupBy('e.id', 'e.nombre_comercial', 'e.razon_social')
            ->orderBy('e.nombre_comercial')
            ->selectRaw('e.id as empresa_id, e.nombre_comercial as empresa, e.razon_social')
            ->selectRaw('MAX(c.id) as ciclo_id, COUNT(DISTINCT c.id) as ciclos')
            ->selectRaw("CASE WHEN COUNT(DISTINCT c.estado) = 1 THEN MAX(c.estado) ELSE 'mixto' END as estado")
            ->selectRaw('COUNT(DISTINCT b.colaborador_id) as colaboradores')
            ->selectRaw('COALESCE(SUM(b.total_ingresos), 0) as total_ingresos')
            ->selectRaw('COALESCE(SUM(b.total_egresos), 0) as total_egresos')
            ->selectRaw('COALESCE(SUM(b.total_aportaciones), 0) as total_aportaciones')
            ->selectRaw('COALESCE(SUM(b.neto_a_pagar), 0) as neto_a_pagar');

        $empresas = $consulta->get()->map(fn ($fila) => [
            'empresa_id' => (int) $fila->empresa_id,
            'empresa' => $fila->empresa,
            'razon_social' => $fila->razon_social,
            'ciclo_id' => (int) $fila->ciclo_id,
            'ciclos' => (int) $fila->ciclos,
            'estado' => $fila->estado,
            'colaboradores' => (int) $fila->colaboradores,
            'total_ingresos' => (float) $fila->total_ingresos,
            'total_egresos' => (float) $fila->total_egresos,
            'total_aportaciones' => (float) $fila->total_aportaciones,
            'neto_a_pagar' => (float) $fila->neto_a_pagar,
        ])->values();

        return [
            'periodo' => $periodo,
            'empresas' => $empresas,
            'totales' => [
                'empresas' => $empresas->count(),
                'colaboradores' => $empresas->sum('colaboradores'),
                'total_ingresos' => round($empresas->sum('total_ingresos'), 2),
                'total_egresos' => round($empresas->sum('total_egresos'), 2),
                'total_aportaciones' => round($empresas->sum('total_aportaciones'), 2),
                'neto_a_pagar' => round($empresas->sum('neto_a_pagar'), 2),
            ],
        ];
    }
}
