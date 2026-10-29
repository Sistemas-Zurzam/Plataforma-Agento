<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Support\Carbon;

/**
 * Indicadores de Selección por empresa (y por áreas autorizadas para GER).
 *
 * Fórmulas y fechas usadas:
 * - Procesos abiertos: estado abierto o en_pausa (al momento de consultar).
 * - Plazas solicitadas/cubiertas/pendientes/canceladas: plazas de procesos
 *   CREADOS en el período (seleccion_procesos.created_at).
 * - Tiempo promedio de cobertura (días): promedio de
 *   (plaza.cubierta_at − proceso.created_at) para plazas CUBIERTAS en el período.
 * - Candidatos por etapa: postulaciones vigentes (activa/seleccionada) hoy.
 * - Fuentes: postulaciones RECIBIDAS en el período (created_at), por fuente.
 * - Atrasados: procesos abiertos/en pausa con fecha_requerida < hoy y plazas
 *   disponibles.
 * - Rotación temprana: de los colaboradores contratados vía Selección en el
 *   período (postulacion.contratada_at), cuántos tienen fecha_cese registrada
 *   en Personas. Sin contrataciones ⇒ "Sin datos suficientes".
 * - Costo de contratación: requiere gastos reales del proceso, que Agento no
 *   registra ⇒ siempre "Sin datos suficientes" (no se estima con salarios).
 */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function resumen(AlcanceSeleccion $alcance, array $filtros): array
    {
        $desde = Carbon::parse($filtros['desde'] ?? now()->subMonths(3)->toDateString())->startOfDay();
        $hasta = Carbon::parse($filtros['hasta'] ?? now()->toDateString())->endOfDay();

        $procesos = fn () => ProcesoSeleccion::query()
            ->where('empresa_id', $alcance->empresaId)
            ->when(! $alcance->accesoTotal(), fn ($q) => $q->whereIn('area_id', $alcance->areaIds))
            ->when($filtros['area_id'] ?? null, fn ($q, $id) => $q->where('area_id', $id))
            ->when($filtros['reclutador_id'] ?? null, fn ($q, $id) => $q->where('reclutador_user_id', $id));

        $procesoIdsPeriodo = $procesos()->whereBetween('created_at', [$desde, $hasta])->pluck('id');
        $procesoIdsTodos = $procesos()->pluck('id');

        $plazasPeriodo = Plaza::whereIn('proceso_id', $procesoIdsPeriodo)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');

        $coberturas = Plaza::whereIn('seleccion_plazas.proceso_id', $procesoIdsTodos)
            ->where('seleccion_plazas.estado', Plaza::CUBIERTA)
            ->whereBetween('seleccion_plazas.cubierta_at', [$desde, $hasta])
            ->join('seleccion_procesos', 'seleccion_procesos.id', '=', 'seleccion_plazas.proceso_id')
            ->get(['seleccion_plazas.cubierta_at', 'seleccion_procesos.created_at as proceso_creado_at'])
            ->map(fn ($fila) => Carbon::parse($fila->proceso_creado_at)->diffInDays(Carbon::parse($fila->cubierta_at)));

        $porEtapa = Postulacion::whereIn('proceso_id', $procesoIdsTodos)
            ->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA])
            ->selectRaw('etapa, COUNT(*) as total')->groupBy('etapa')->pluck('total', 'etapa');

        $fuentes = Postulacion::whereIn('proceso_id', $procesoIdsTodos)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('fuente, COUNT(*) as total')->groupBy('fuente')->pluck('total', 'fuente');

        $atrasados = $procesos()
            ->whereIn('estado', [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA])
            ->whereDate('fecha_requerida', '<', today())
            ->whereHas('plazas', fn ($q) => $q->where('estado', Plaza::DISPONIBLE))
            ->with('area:id,nombre')
            ->withCount(['plazas as plazas_pendientes' => fn ($q) => $q->where('estado', Plaza::DISPONIBLE)])
            ->orderBy('fecha_requerida')
            ->limit(20)
            ->get(['id', 'codigo', 'cargo', 'area_id', 'fecha_requerida', 'estado']);

        $contratados = Postulacion::whereIn('proceso_id', $procesoIdsTodos)
            ->where('estado', FlujoPostulacion::CONTRATADA)
            ->whereBetween('contratada_at', [$desde, $hasta])
            ->whereNotNull('colaborador_id')
            ->pluck('colaborador_id');
        $cesados = $contratados->isEmpty() ? 0 : Colaborador::whereIn('id', $contratados)->whereNotNull('fecha_cese')->count();

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'procesos_abiertos' => $procesos()->whereIn('estado', [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA])->count(),
            'plazas' => [
                'solicitadas' => (int) $plazasPeriodo->sum(),
                'cubiertas' => (int) ($plazasPeriodo[Plaza::CUBIERTA] ?? 0),
                'pendientes' => (int) ($plazasPeriodo[Plaza::DISPONIBLE] ?? 0),
                'canceladas' => (int) ($plazasPeriodo[Plaza::CANCELADA] ?? 0),
            ],
            'tiempo_promedio_cobertura_dias' => $coberturas->isEmpty() ? null : round($coberturas->avg(), 1),
            'candidatos_por_etapa' => collect(FlujoPostulacion::ETAPAS)->mapWithKeys(fn ($e) => [$e => (int) ($porEtapa[$e] ?? 0)]),
            'fuentes' => $fuentes->map(fn ($t) => (int) $t),
            'procesos_atrasados' => $atrasados,
            'rotacion_temprana' => $contratados->isEmpty()
                ? ['disponible' => false, 'mensaje' => 'Sin datos suficientes']
                : ['disponible' => true, 'contratados' => $contratados->count(), 'cesados' => $cesados, 'porcentaje' => round($cesados / $contratados->count() * 100, 1)],
            'costo_contratacion' => ['disponible' => false, 'mensaje' => 'Sin datos suficientes: Agento no registra gastos de reclutamiento.'],
            'tareas_pendientes' => $alcance->accesoTotal() || $alcance->puede('colaboradores.editar')
                ? Tarea::where('empresa_id', $alcance->empresaId)->where('estado', 'pendiente')->count()
                : null,
        ];
    }
}
