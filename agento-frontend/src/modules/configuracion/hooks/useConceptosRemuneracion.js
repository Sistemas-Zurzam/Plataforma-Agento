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

  return { conceptos, loading, fetchConceptos };
}
