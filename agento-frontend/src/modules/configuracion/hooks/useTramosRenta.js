import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useTramosRenta() {
  const [categorias, setCategorias] = useState({});
  const [loading, setLoading] = useState(false);

  const fetchTramos = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/tramos-renta');
      setCategorias(data.categorias);
      return data.categorias;
    } finally {
      setLoading(false);
    }
  }, []);

  return { categorias, loading, fetchTramos };
}
