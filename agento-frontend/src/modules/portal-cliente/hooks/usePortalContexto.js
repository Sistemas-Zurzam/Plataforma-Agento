import { useCallback, useState } from 'react';
import api from '../../../services/api';

/**
 * El contexto (empresa activa, rol, permisos del portal) siempre se resuelve
 * en el backend a partir del usuario autenticado — este hook nunca envía ni
 * recibe un empresa_id para decidir nada, solo refleja lo que /portal/contexto
 * ya resolvió del lado del servidor.
 */
export function usePortalContexto() {
  const [contexto, setContexto] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchContexto = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get('/portal/contexto');
      setContexto(data.data);
      return data.data;
    } catch (err) {
      setError(err);
      setContexto(null);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { contexto, loading, error, fetchContexto };
}
