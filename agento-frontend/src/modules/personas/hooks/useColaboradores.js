import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useColaboradores() {
  const [colaboradores, setColaboradores] = useState([]);
  const [stats, setStats] = useState({ total: 0, activos: 0 });
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 10, total: 0 });

  const fetchColaboradores = useCallback(async (page = 1, perPage = 10, busqueda = '') => {
    setLoading(true);
    try {
      const { data } = await api.get('/colaboradores', {
        params: { page, per_page: perPage, busqueda: busqueda || undefined },
      });
      setColaboradores(data.data);
      setStats(data.stats);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearColaborador = useCallback(async (values) => {
    const { data } = await api.post('/colaboradores', values);
    return data.data;
  }, []);

  const fetchCalendarioDefecto = useCallback(async (horarioId, fechaIngreso) => {
    const { data } = await api.get('/colaboradores/calendario-defecto', {
      params: { horario_id: horarioId, fecha_ingreso: fechaIngreso },
    });
    return data.dias;
  }, []);

  const fetchCalendarioDelMes = useCallback(async (colaboradorId, anio, mes) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}/calendario`, {
      params: { anio, mes },
    });
    return data.dias;
  }, []);

  const fetchColaborador = useCallback(async (colaboradorId) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}`);
    return data.data;
  }, []);

  const actualizarCalendario = useCallback(async (colaboradorId, dias) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/calendario`, { dias });
    return data.data;
  }, []);

  const actualizarHorario = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/horario`, values);
    return data.data;
  }, []);

  const actualizarColaborador = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}`, values);
    return data.data;
  }, []);

  const cesarColaborador = useCallback(async (colaboradorId, values) => {
    const { data } = await api.patch(`/colaboradores/${colaboradorId}/cesar`, values);
    return data.data;
  }, []);

  const eliminarColaborador = useCallback(async (colaboradorId) => {
    await api.delete(`/colaboradores/${colaboradorId}`);
  }, []);

  const subirDocumento = useCallback(async (colaboradorId, tipo, archivo) => {
    const formData = new FormData();
    formData.append('tipo', tipo);
    formData.append('archivo', archivo);
    const { data } = await api.post(`/colaboradores/${colaboradorId}/documentos`, formData);
    return data.data;
  }, []);

  const verDocumento = useCallback(async (colaboradorId, documentoId) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}/documentos/${documentoId}`, {
      responseType: 'blob',
    });
    return data;
  }, []);

  return {
    colaboradores,
    stats,
    loading,
    pagination,
    fetchColaboradores,
    crearColaborador,
    fetchCalendarioDefecto,
    fetchCalendarioDelMes,
    fetchColaborador,
    actualizarCalendario,
    actualizarHorario,
    actualizarColaborador,
    cesarColaborador,
    eliminarColaborador,
    subirDocumento,
    verDocumento,
  };
}
