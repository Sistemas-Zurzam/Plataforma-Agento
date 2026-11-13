import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useAuditoria() {
  const [eventos, setEventos] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 25, total: 0 });
  const [filtrosDisponibles, setFiltrosDisponibles] = useState({ usuarios: [], modulos: [] });

  const fetchEventos = useCallback(async (filtros = {}, page = 1, perPage = 25) => {
    setLoading(true);
    try {
      const params = Object.fromEntries(
        Object.entries({ ...filtros, page, per_page: perPage })
          .filter(([, valor]) => valor !== undefined && valor !== null && valor !== ''),
      );
      const { data } = await api.get('/auditoria/eventos', { params });
      setEventos(data.data);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchFiltros = useCallback(async () => {
    const { data } = await api.get('/auditoria/filtros');
    setFiltrosDisponibles(data.data);
  }, []);

  const fetchDetalle = useCallback(async (id) => {
    const { data } = await api.get(`/auditoria/eventos/${id}`);
    return data.data;
  }, []);

  return { eventos, loading, pagination, filtrosDisponibles, fetchEventos, fetchFiltros, fetchDetalle };
}
