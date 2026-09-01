import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useConceptoDefinicionesPlame() {
  const [definiciones, setDefiniciones] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchDefiniciones = useCallback(async (conceptoId) => {
    // Evita mostrar las clasificaciones del concepto anterior mientras
    // llega la nueva respuesta.
    setDefiniciones([]);
    setLoading(true);
    try {
      const { data } = await api.get(`/conceptos-remuneracion/${conceptoId}/definiciones-plame`);
      setDefiniciones(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearDefinicion = useCallback(async (conceptoId, valores) => {
    const { data } = await api.post(`/conceptos-remuneracion/${conceptoId}/definiciones-plame`, valores);
    setDefiniciones((actuales) => [...actuales, data.data]);
    return data.data;
  }, []);

  const actualizarDefinicion = useCallback(async (definicionId, valores) => {
    const { data } = await api.put(`/concepto-definiciones-plame/${definicionId}`, valores);
    setDefiniciones((actuales) => actuales.map((d) => (d.id === definicionId ? data.data : d)));
    return data.data;
  }, []);

  return { definiciones, loading, fetchDefiniciones, crearDefinicion, actualizarDefinicion };
}
