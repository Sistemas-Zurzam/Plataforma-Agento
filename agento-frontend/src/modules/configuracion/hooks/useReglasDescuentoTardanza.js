import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useReglasDescuentoTardanza(empresaId) {
  const [reglas, setReglas] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchReglas = useCallback(async () => {
    if (!empresaId) {
      setReglas([]);
      return [];
    }
    setLoading(true);
    try {
      const { data } = await api.get(`/empresas/${empresaId}/reglas-tardanza`);
      setReglas(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, [empresaId]);

  const crearRegla = useCallback(
    async (values) => {
      const { data } = await api.post(`/empresas/${empresaId}/reglas-tardanza`, values);
      setReglas((prev) => [...prev, data.data]);
      return data.data;
    },
    [empresaId],
  );

  const eliminarRegla = useCallback(
    async (reglaId) => {
      await api.delete(`/empresas/${empresaId}/reglas-tardanza/${reglaId}`);
      setReglas((prev) => prev.filter((regla) => regla.id !== reglaId));
    },
    [empresaId],
  );

  return { reglas, loading, fetchReglas, crearRegla, eliminarRegla };
}
