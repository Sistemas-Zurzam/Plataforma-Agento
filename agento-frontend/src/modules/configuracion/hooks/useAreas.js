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

  const asignarResponsable = useCallback(
    async (areaId, responsableUserId) => {
      const { data } = await api.patch(`/empresas/${empresaId}/areas/${areaId}/responsable`, {
        responsable_user_id: responsableUserId,
      });
      setAreas((prev) => prev.map((area) => (area.id === areaId ? data.data : area)));
      return data.data;
    },
    [empresaId],
  );

  return { areas, loading, fetchAreas, createArea, asignarResponsable };
}
