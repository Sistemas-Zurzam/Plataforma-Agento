import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useParametrosLaborales() {
  const [regimenes, setRegimenes] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchParametros = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/parametros-laborales');
      setRegimenes(data.regimenes);
      return data;
    } finally {
      setLoading(false);
    }
  }, []);

  const guardarValores = useCallback(async (regimen, vigenciaDesde, valoresPorDefinicion) => {
    const { data } = await api.post('/parametros-laborales', {
      regimen_laboral: regimen,
      vigencia_desde: vigenciaDesde,
      valores: valoresPorDefinicion,
    });
    setRegimenes(data.regimenes);
    return data;
  }, []);

  const inicializarPorDefecto = useCallback(async () => {
    const { data } = await api.post('/parametros-laborales/inicializar');
    setRegimenes(data.regimenes);
    return data;
  }, []);

  return { regimenes, loading, fetchParametros, guardarValores, inicializarPorDefecto };
}
