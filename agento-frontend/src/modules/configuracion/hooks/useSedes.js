import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useSedes(empresaId, soloActivas = false) {
  const [sedes, setSedes] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchSedes = useCallback(async () => {
    if (!empresaId) return;
    setLoading(true);
    try {
      const { data } = await api.get(`/empresas/${empresaId}/sedes`, {
        params: soloActivas ? { solo_activas: true } : undefined,
      });
      setSedes(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, [empresaId, soloActivas]);

  const createSede = useCallback(
    async (values) => {
      const { data } = await api.post(`/empresas/${empresaId}/sedes`, values);
      setSedes((prev) => [...prev, data.data]);
      return data.data;
    },
    [empresaId],
  );

  const updateSede = useCallback(
    async (sedeId, values) => {
      const { data } = await api.put(`/empresas/${empresaId}/sedes/${sedeId}`, values);
      setSedes((prev) => prev.map((sede) => (sede.id === sedeId ? data.data : sede)));
      return data.data;
    },
    [empresaId],
  );

  return { sedes, loading, fetchSedes, createSede, updateSede };
}
