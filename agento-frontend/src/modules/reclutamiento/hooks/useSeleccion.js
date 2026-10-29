import { useCallback } from 'react';
import { seleccionApi } from '../services/seleccionApi';
import { useConsulta } from './useConsulta';

/**
 * Hooks del módulo de Selección: cada página consume uno de estos hooks y
 * los hooks llaman al servicio de API (página → hook → servicio → Axios).
 */

export function useDashboardSeleccion(filtros) {
  const cargar = useCallback(() => seleccionApi.dashboard(filtros), [filtros]);
  return useConsulta(cargar);
}

export function useTareasSeleccion(estado = 'pendiente', habilitado = true) {
  const cargar = useCallback(() => seleccionApi.tareas({ estado, per_page: 20 }), [estado]);
  const consulta = useConsulta(cargar, { inmediata: habilitado });
  return { ...consulta, completar: seleccionApi.completarTarea };
}

export function useSolicitudes(filtros) {
  const cargar = useCallback(() => seleccionApi.solicitudes.listar(filtros), [filtros]);
  const consulta = useConsulta(cargar);
  return { ...consulta, acciones: seleccionApi.solicitudes };
}

export function useReferenciasSolicitud(habilitado) {
  const cargar = useCallback(() => seleccionApi.solicitudes.referencias(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function useProcesos(filtros) {
  const cargar = useCallback(() => seleccionApi.procesos.listar(filtros), [filtros]);
  return useConsulta(cargar);
}

export function useProcesoDetalle(procesoId) {
  const cargar = useCallback(() => seleccionApi.procesos.obtener(procesoId), [procesoId]);
  const consulta = useConsulta(cargar);
  return { ...consulta, acciones: seleccionApi.procesos };
}

export function usePerfilProceso(procesoId) {
  const cargar = useCallback(
    () => (procesoId ? seleccionApi.procesos.obtener(procesoId).then((r) => r.data.perfil) : Promise.resolve(null)),
    [procesoId],
  );
  return useConsulta(cargar);
}

export function useActividadProceso(procesoId) {
  const cargar = useCallback(() => seleccionApi.procesos.actividad(procesoId), [procesoId]);
  return useConsulta(cargar);
}

export function useTablero(procesoId, filtros, habilitado = true) {
  const cargar = useCallback(() => seleccionApi.procesos.tablero(procesoId, filtros), [procesoId, filtros]);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function usePostulaciones(filtros, habilitado = true) {
  const cargar = useCallback(() => seleccionApi.postulaciones.listar(filtros), [filtros]);
  const consulta = useConsulta(cargar, { inmediata: habilitado });
  return { ...consulta, acciones: seleccionApi.postulaciones };
}

export function usePostulacion(postulacionId) {
  const cargar = useCallback(
    () => (postulacionId ? seleccionApi.postulaciones.obtener(postulacionId) : Promise.resolve(null)),
    [postulacionId],
  );
  const consulta = useConsulta(cargar);
  return { ...consulta, acciones: seleccionApi.postulaciones, entrevistas: seleccionApi.entrevistas };
}

export function useEntrevistasPropias() {
  const cargar = useCallback(() => seleccionApi.entrevistas.mias(), []);
  const consulta = useConsulta(cargar);
  return { ...consulta, evaluar: seleccionApi.entrevistas.evaluar };
}

export function usePruebas(filtros, habilitado = true) {
  const cargar = useCallback(() => seleccionApi.pruebas.listar(filtros), [filtros]);
  const consulta = useConsulta(cargar, { inmediata: habilitado });
  return { ...consulta, acciones: seleccionApi.pruebas };
}

export function useBancoTalentos(filtros) {
  const cargar = useCallback(() => seleccionApi.banco.listar(filtros), [filtros]);
  const consulta = useConsulta(cargar);
  return { ...consulta, acciones: seleccionApi.banco };
}

export function useCandidatoBanco(candidatoId) {
  const cargar = useCallback(
    () => (candidatoId ? seleccionApi.banco.obtener(candidatoId) : Promise.resolve(null)),
    [candidatoId],
  );
  const consulta = useConsulta(cargar);
  return {
    ...consulta,
    etiquetar: seleccionApi.banco.etiquetar,
    quitarEtiqueta: seleccionApi.banco.quitarEtiqueta,
    invitar: seleccionApi.procesos.invitar,
  };
}

export function useCoincidencias(procesoId, habilitado) {
  const cargar = useCallback(() => seleccionApi.procesos.coincidencias(procesoId), [procesoId]);
  const consulta = useConsulta(cargar, { inmediata: habilitado });
  return { ...consulta, invitar: seleccionApi.procesos.invitar };
}

export function useUsuariosSeleccion(habilitado = true) {
  const cargar = useCallback(() => seleccionApi.usuarios(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function useOpcionesContratacion(habilitado) {
  const cargar = useCallback(() => seleccionApi.contratacion.opciones(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}
