import { useCallback } from 'react';
import { useConsulta } from '../../reclutamiento/hooks/useConsulta';
import { portalApi } from '../services/portalApi';

/**
 * Hooks de lectura del Portal de Empresa. Reutilizan useConsulta (descarta
 * respuestas de consultas reemplazadas al cambiar filtros rápido). Las
 * acciones se exponen tal cual desde portalApi para que cada pantalla
 * maneje su propio estado de envío y errores por campo.
 */
export function useInicioPortal() {
  const cargar = useCallback(() => portalApi.inicio(), []);
  return useConsulta(cargar);
}

export function useEquipoMensual(filtros) {
  const { mes, area_id: areaId, busqueda, page } = filtros;
  const cargar = useCallback(
    () => portalApi.equipo.mensual({ mes, area_id: areaId, busqueda: busqueda || undefined, page, per_page: 15 }),
    [mes, areaId, busqueda, page],
  );
  return useConsulta(cargar);
}

export function useIncidenciasEquipo(filtros, habilitado) {
  const { fecha_desde: desde, fecha_hasta: hasta, colaborador_id: colaboradorId } = filtros;
  const cargar = useCallback(
    () => portalApi.equipo.incidencias({ fecha_desde: desde, fecha_hasta: hasta, colaborador_id: colaboradorId, per_page: 50 }),
    [desde, hasta, colaboradorId],
  );
  return useConsulta(cargar, { inmediata: habilitado });
}

export function useColaboradoresEquipo() {
  const cargar = useCallback(() => portalApi.equipo.colaboradores({ estado: 'activo', per_page: 50 }), []);
  return useConsulta(cargar);
}

export function useHorasExtraPortal(filtros, habilitado) {
  const { fecha_desde: desde, fecha_hasta: hasta, estado, page } = filtros;
  const cargar = useCallback(
    () => portalApi.horasExtra.listar({ fecha_desde: desde, fecha_hasta: hasta, estado, page, per_page: 15 }),
    [desde, hasta, estado, page],
  );
  return { ...useConsulta(cargar, { inmediata: habilitado }), decidir: portalApi.horasExtra.decidir };
}

export function usePermisosPortal(filtros, habilitado) {
  const { fecha_desde: desde, fecha_hasta: hasta, estado, page } = filtros;
  const cargar = useCallback(
    () => portalApi.permisos.listar({ fecha_desde: desde, fecha_hasta: hasta, estado, page, per_page: 15 }),
    [desde, hasta, estado, page],
  );
  return { ...useConsulta(cargar, { inmediata: habilitado }), solicitar: portalApi.permisos.solicitar };
}

export function useTiposPermiso(habilitado) {
  const cargar = useCallback(() => portalApi.permisos.tipos(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function usePlanificacionPortal(filtros) {
  const { semana, area_id: areaId, busqueda, solo_rotativos: soloRotativos } = filtros;
  const cargar = useCallback(
    () => portalApi.planificacion.semana({ semana, area_id: areaId, busqueda: busqueda || undefined, solo_rotativos: soloRotativos ? 1 : 0 }),
    [semana, areaId, busqueda, soloRotativos],
  );
  return { ...useConsulta(cargar), guardar: portalApi.planificacion.guardar };
}

export function useBonosPortal(filtros) {
  const { estado, page } = filtros;
  const cargar = useCallback(() => portalApi.bonos.listar({ estado, page, per_page: 15 }), [estado, page]);
  return { ...useConsulta(cargar), proponer: portalApi.bonos.proponer };
}

export function useCiclosAbiertos(habilitado) {
  const cargar = useCallback(() => portalApi.bonos.ciclos(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function useCesesPortal(filtros) {
  const { etapa, page } = filtros;
  const cargar = useCallback(() => portalApi.ceses.listar({ etapa, page, per_page: 15 }), [etapa, page]);
  return { ...useConsulta(cargar), solicitar: portalApi.ceses.solicitar, evidencia: portalApi.ceses.evidencia };
}

export function usePersonalPortal(filtros) {
  const { estado, page } = filtros;
  const cargar = useCallback(() => portalApi.personal.listar({ estado, page, per_page: 15 }), [estado, page]);
  return useConsulta(cargar);
}
