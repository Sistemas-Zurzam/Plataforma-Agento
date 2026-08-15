import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useHorarios() {
  const [horarios, setHorarios] = useState([]);
  const [stats, setStats] = useState({ total: 0, activos: 0, pendientes: 0 });
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 10, total: 0 });

  const fetchHorarios = useCallback(async (page = 1, perPage = 10, busqueda = '', estado = '') => {
    setLoading(true);
    try {
      const { data } = await api.get('/horarios', {
        params: { page, per_page: perPage, busqueda: busqueda || undefined, estado: estado || undefined },
      });
      setHorarios(data.data);
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

  const crearHorario = useCallback(async (values) => {
    const { data } = await api.post('/horarios', values);
    return data.data;
  }, []);

  const actualizarHorario = useCallback(async (horarioId, values) => {
    const { data } = await api.put(`/horarios/${horarioId}`, values);
    return data.data;
  }, []);

  const duplicarHorario = useCallback(async (horarioId) => {
    const { data } = await api.post(`/horarios/${horarioId}/duplicar`);
    return data.data;
  }, []);

  const cambiarEstado = useCallback(async (horarioId) => {
    const { data } = await api.patch(`/horarios/${horarioId}/estado`);
    return data.data;
  }, []);

  return {
    horarios,
    stats,
    loading,
    pagination,
    fetchHorarios,
    crearHorario,
    actualizarHorario,
    duplicarHorario,
    cambiarEstado,
  };
}
