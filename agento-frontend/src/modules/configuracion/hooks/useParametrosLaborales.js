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

  const guardarValores = useCallback(async (regimen, vigenciaDesde, valoresPorDefinicion, motivo) => {
    const { data } = await api.post('/parametros-laborales', {
      regimen_laboral: regimen,
      vigencia_desde: vigenciaDesde,
      valores: valoresPorDefinicion,
      motivo: motivo || undefined,
    });
    setRegimenes(data.regimenes);
    return data;
  }, []);

  const inicializarPorDefecto = useCallback(async () => {
    const { data } = await api.post('/parametros-laborales/inicializar');
    setRegimenes(data.regimenes);
    return data;
  }, []);

  const fetchHistorial = useCallback(async (definicionId, regimenLaboral) => {
    const { data } = await api.get(`/parametros-laborales/${definicionId}/historial`, {
      params: { regimen_laboral: regimenLaboral },
    });
    return data.data;
  }, []);

  return {
    regimenes,
    loading,
    fetchParametros,
    guardarValores,
    inicializarPorDefecto,
    fetchHistorial,
  };
}
