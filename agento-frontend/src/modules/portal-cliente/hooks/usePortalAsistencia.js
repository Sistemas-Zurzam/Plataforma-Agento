import { useCallback, useState } from 'react';
import api from '../../../services/api';

/**
 * Un método por endpoint de solo lectura de /api/portal/asistencia/*. Nunca
 * envía empresa_id — el backend siempre resuelve la empresa desde el
 * usuario autenticado.
 */
export function usePortalAsistencia() {
  const [loading, setLoading] = useState(false);

  const conCarga = useCallback(async (accion) => {
    setLoading(true);
    try {
      return await accion();
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchResumen = useCallback(
    (params) => conCarga(async () => (await api.get('/portal/asistencia/resumen', { params })).data.data),
    [conCarga],
  );

  const fetchColaboradores = useCallback(
    (params) => conCarga(async () => (await api.get('/portal/asistencia/colaboradores', { params })).data),
    [conCarga],
  );

  const fetchColaborador = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}`, { params })).data),
    [conCarga],
  );

  const fetchCalendario = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/calendario`, { params })).data.data),
    [conCarga],
  );

  const fetchMarcaciones = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/marcaciones`, { params })).data.data),
    [conCarga],
  );

  const fetchIncidenciasDeColaborador = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/incidencias`, { params })).data),
    [conCarga],
  );

  const fetchHorasExtraDeColaborador = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/horas-extra`, { params })).data),
    [conCarga],
  );

  const fetchPermisosDeColaborador = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/permisos`, { params })).data),
    [conCarga],
  );

  const fetchHistorial = useCallback(
    (colaboradorId, params) => conCarga(async () =>
      (await api.get(`/portal/asistencia/colaboradores/${colaboradorId}/historial`, { params })).data),
    [conCarga],
  );

  const fetchIncidencias = useCallback(
    (params) => conCarga(async () => (await api.get('/portal/asistencia/incidencias', { params })).data),
    [conCarga],
  );

  const fetchHorasExtra = useCallback(
    (params) => conCarga(async () => (await api.get('/portal/asistencia/horas-extra', { params })).data),
    [conCarga],
  );

  const fetchPermisos = useCallback(
    (params) => conCarga(async () => (await api.get('/portal/asistencia/permisos', { params })).data),
    [conCarga],
  );

  return {
    loading,
    fetchResumen,
    fetchColaboradores,
    fetchColaborador,
    fetchCalendario,
    fetchMarcaciones,
    fetchIncidenciasDeColaborador,
    fetchHorasExtraDeColaborador,
    fetchPermisosDeColaborador,
    fetchHistorial,
    fetchIncidencias,
    fetchHorasExtra,
    fetchPermisos,
  };
}
