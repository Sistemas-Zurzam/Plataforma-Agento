import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useAreas(empresaId) {
  const [areas, setAreas] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchAreas = useCallback(async () => {
    if (!empresaId) {
      setAreas([]);
      return [];
    }
    setLoading(true);
    try {
      const { data } = await api.get(`/empresas/${empresaId}/areas`);
      setAreas(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, [empresaId]);

  const createArea = useCallback(
    async (nombre) => {
      const { data } = await api.post(`/empresas/${empresaId}/areas`, { nombre });
      setAreas((prev) => [...prev, data.data]);
      return data.data;
    },
    [empresaId],
  );

  return { areas, loading, fetchAreas, createArea };
}
