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

  return {
    colaboradores,
    stats,
    loading,
    pagination,
    fetchColaboradores,
    crearColaborador,
    fetchCalendarioDefecto,
  };
}
