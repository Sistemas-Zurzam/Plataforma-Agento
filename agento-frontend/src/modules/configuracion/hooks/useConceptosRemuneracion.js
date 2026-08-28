import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useConceptosRemuneracion() {
  const [conceptos, setConceptos] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchConceptos = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/conceptos-remuneracion');
      setConceptos(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const actualizarCodigoPlame = useCallback(async (conceptoId, valores) => {
    const payload = typeof valores === 'string' || valores === null
      ? { codigo_plame: valores || null }
      : valores;
    const { data } = await api.patch(`/conceptos-remuneracion/${conceptoId}/codigo-plame`, payload);
    setConceptos((actuales) => actuales.map((c) => (c.id === conceptoId ? data.data : c)));
    return data.data;
  }, []);

  const fetchHistorialCodigoPlame = useCallback(async (conceptoId) => {
    const { data } = await api.get(`/conceptos-remuneracion/${conceptoId}/codigo-plame/historial`);
    return data.data;
  }, []);

  return { conceptos, loading, fetchConceptos, actualizarCodigoPlame, fetchHistorialCodigoPlame };
}
