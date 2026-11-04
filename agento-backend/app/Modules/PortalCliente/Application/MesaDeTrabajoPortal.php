<?php

namespace App\Modules\PortalCliente\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Services\PlanificacionRotativaService;
use App\Modules\Nominas\Models\PropuestaConcepto;
use App\Modules\Personas\Models\SolicitudCese;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use App\Modules\Reclutamiento\Application\SeguimientoSolicitudes;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Support\Carbon;

/**
 * Inicio del portal: pendientes REALES del alcance, calculados con las
 * mismas consultas/filtros que sus listados, y con el período explícito
 * para que el enlace abra exactamente el mismo conjunto. Un indicador sin
 * permiso se devuelve como null (no como 0).
 */
class MesaDeTrabajoPortal
{
    public const DIAS_HORAS_EXTRA = 31;

    public function __construct(
        private readonly PlanificacionRotativaService $planificacion,
        private readonly SeguimientoSolicitudes $seguimiento,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resumen(AlcancePortal $alcance): array
    {
        $hoy = Carbon::now(config('portal_cliente.zona_horaria_negocio'));

        return [
            'generado_at' => now()->toDateTimeString(),
            'horas_extra' => $this->horasExtra($alcance, $hoy),
            'rotativos' => $this->rotativos($alcance, $hoy),
            'personal' => $this->personal($alcance),
            'ceses' => $alcance->puede('portal.ceses.solicitar') ? [
                'en_curso' => $this->cesesBase($alcance)
                    ->where(fn ($q) => $q->where('estado', SolicitudCese::ESTADO_ENVIADA)
                        ->orWhere(fn ($q) => $q->where('estado', SolicitudCese::ESTADO_APROBADA)->whereNull('ejecutada_at')))
                    ->count(),
            ] : null,
            'bonos' => $alcance->puede('portal.bonos.proponer') ? [
                'propuestos' => $alcance->restringirConPropias(PropuestaConcepto::query(), 'nomina_propuestas_concepto', 'propuesto_por')
                    ->where('estado', PropuestaConcepto::ESTADO_PROPUESTO)->count(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function horasExtra(AlcancePortal $alcance, Carbon $hoy): ?array
    {
        if (! $alcance->puede('portal.horas_extra.ver')) {
            return null;
        }
        $desde = $hoy->copy()->subDays(self::DIAS_HORAS_EXTRA - 1)->toDateString();
        $hasta = $hoy->toDateString();

        return [
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'por_aprobar' => $alcance->restringir(AsistenciaHoraExtra::query(), 'asistencia_horas_extra')
                ->whereBetween('fecha', [$desde, $hasta])
                ->where('estado', AsistenciaHoraExtra::ESTADO_PENDIENTE)->count(),
            'en_validacion_rrhh' => $alcance->restringir(AsistenciaHoraExtra::query(), 'asistencia_horas_extra')
                ->whereBetween('fecha', [$desde, $hasta])
                ->where('estado', AsistenciaHoraExtra::ESTADO_APROBADO_GERENCIA)->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rotativos(AlcancePortal $alcance, Carbon $hoy): ?array
    {
        if (! $alcance->puede('portal.planificacion.gestionar')) {
            return null;
        }
        $semana = $this->planificacion->consultarSemana(
            $alcance->empresa, $hoy->toDateString(), null, true, null,
            fn ($query) => $query->whereIn('colaboradores.id', $alcance->colaboradores()->select('colaboradores.id')),
        );
        $sinPlanificar = collect($semana['colaboradores'])->filter(fn ($c) => $c['dias_sin_planificar'] > 0);

        return [
            'semana' => $semana['semana'],
            'colaboradores_sin_planificar' => $sinPlanificar->count(),
            'dias_sin_planificar' => $sinPlanificar->sum('dias_sin_planificar'),
            'con_descuadre' => collect($semana['colaboradores'])->where('estado', 'descuadre')->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function personal(AlcancePortal $alcance): ?array
    {
        if (! $alcance->puede('seleccion.solicitudes.crear') && ! $alcance->puede('seleccion.seguimiento')) {
            return null;
        }
        $alcanceSeleccion = AlcanceSeleccion::de($alcance->usuario);
        $base = SolicitudPersonal::query()
            ->where('empresa_id', $alcance->empresa->id)
            ->when(! $alcanceSeleccion->accesoTotal(), fn ($q) => $q->where(fn ($sub) => $sub
                ->whereIn('area_id', $alcanceSeleccion->areaIds)
                ->orWhere('solicitante_user_id', $alcance->usuario->id)));

        $aceptadas = (clone $base)->where('estado', 'aceptada')->pluck('id')->all();
        $avance = collect($this->seguimiento->porSolicitudes($alcance->empresa->id, $aceptadas));

        return [
            'devueltas' => (clone $base)->where('estado', 'devuelta')->count(),
            'borradores' => (clone $base)->where('estado', 'borrador')->count(),
            'enviadas' => (clone $base)->where('estado', 'enviada')->count(),
            'en_proceso' => count($aceptadas),
            'plazas_vigentes' => $avance->sum('plazas_vigentes'),
            'plazas_cubiertas' => $avance->sum('plazas_cubiertas'),
        ];
    }

    private function cesesBase(AlcancePortal $alcance)
    {
        return $alcance->restringirConPropias(SolicitudCese::query(), 'personas_solicitudes_cese', 'solicitante_user_id');
    }
}
