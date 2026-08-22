import { useCallback, useState } from 'react';
import api from '../../../services/api';

/**
 * Catálogo de AFP (fuente única: Configuraciones). Cualquier módulo que
 * necesite listar AFPs para un select (Personas, Remuneraciones) debe usar
 * este hook en vez de mantener su propia copia hardcodeada o su propio
 * fetch — evita que un cambio de catálogo en el backend deje desincronizado
 * a un formulario que nadie recuerda actualizar.
 */
export function useAfps() {
  const [afps, setAfps] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchAfps = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/afps');
      setAfps(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  return { afps, loading, fetchAfps };
}
