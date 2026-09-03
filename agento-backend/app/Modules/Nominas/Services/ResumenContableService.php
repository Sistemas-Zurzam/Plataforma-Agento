<?php

namespace App\Modules\Nominas\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        $complementariasPorEmpresa = $this->complementariasPorEmpresa($empresaIds, $inicio, $fin, $estado, $categoria);

        $empresas = $consulta->get()->map(function ($fila) use ($complementariasPorEmpresa) {
            $ajuste = (float) $complementariasPorEmpresa->get((int) $fila->empresa_id, 0.0);

            return [
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
                // Ajuste neto (puede ser negativo) de planillas complementarias
                // aprobadas/pagadas de este período — nunca "calculada" (sin
                // aprobar todavía no es un compromiso confirmado). La boleta
                // original que corrigen ya está sumada arriba en b.neto_a_pagar,
                // así que esto se SUMA aparte, nunca se reemplaza.
                'total_complementarias' => round($ajuste, 2),
                'neto_a_pagar' => round((float) $fila->neto_a_pagar + $ajuste, 2),
            ];
        })->values();

        return [
            'periodo' => $periodo,
            'empresas' => $empresas,
            'totales' => [
                'empresas' => $empresas->count(),
                'colaboradores' => $empresas->sum('colaboradores'),
                'total_ingresos' => round($empresas->sum('total_ingresos'), 2),
                'total_egresos' => round($empresas->sum('total_egresos'), 2),
                'total_aportaciones' => round($empresas->sum('total_aportaciones'), 2),
                'total_complementarias' => round($empresas->sum('total_complementarias'), 2),
                'neto_a_pagar' => round($empresas->sum('neto_a_pagar'), 2),
            ],
        ];
    }

    /**
     * Suma de diferencia_neta de complementarias aprobadas/pagadas por
     * empresa, en el mismo período/filtros que el resto del resumen. Se
     * calcula en una consulta aparte (no un JOIN más sobre la principal)
     * porque unir planilla_complementaria_detalles a la consulta de
     * boletas multiplicaría cada boleta por cada detalle de complementaria
     * del mismo ciclo — un error de agregación clásico de SQL, no una
     * preferencia de estilo.
     *
     * @param  array<int, int>  $empresaIds
     * @return Collection<int, float> indexada por empresa_id
     */
    private function complementariasPorEmpresa(array $empresaIds, string $inicio, string $fin, ?string $estado, ?string $categoria): Collection
    {
        return DB::table('planillas_complementarias as pc')
            ->join('ciclos_remunerativos as c', 'c.id', '=', 'pc.ciclo_id')
            ->join('planilla_complementaria_detalles as pcd', 'pcd.planilla_complementaria_id', '=', 'pc.id')
            ->join('boletas as bo', 'bo.id', '=', 'pcd.boleta_original_id')
            ->whereIn('pc.empresa_id', $empresaIds)
            ->whereIn('pc.estado', ['aprobada', 'pagada'])
            ->whereDate('c.fecha_inicio', '<=', $fin)
            ->whereDate('c.fecha_fin', '>=', $inicio)
            ->when($estado, fn ($query) => $query->where('c.estado', $estado))
            ->when($categoria === 'honorarios', fn ($query) => $query->where('bo.regimen_laboral_snapshot', 'Locacion de Servicios'))
            ->when($categoria === 'planilla', fn ($query) => $query->where('bo.regimen_laboral_snapshot', '!=', 'Locacion de Servicios'))
            ->groupBy('pc.empresa_id')
            ->selectRaw('pc.empresa_id as empresa_id, COALESCE(SUM(pcd.diferencia_neta), 0) as total')
            ->get()
            ->pluck('total', 'empresa_id');
    }
}
