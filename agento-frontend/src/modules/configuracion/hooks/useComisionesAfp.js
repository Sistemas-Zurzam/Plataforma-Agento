import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useComisionesAfp() {
  const [afps, setAfps] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchComisiones = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/comisiones-afp');
      setAfps(data.afps);
      return data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearComision = useCallback(async (datos) => {
    const { data } = await api.post('/comisiones-afp', datos);
    setAfps(data.afps);
    return data;
  }, []);

  const actualizarComision = useCallback(async (comisionId, datos) => {
    const { data } = await api.put(`/comisiones-afp/${comisionId}`, datos);
    setAfps(data.afps);
    return data;
  }, []);

  const eliminarComision = useCallback(async (comisionId) => {
    const { data } = await api.delete(`/comisiones-afp/${comisionId}`);
    setAfps(data.afps);
    return data;
  }, []);

  const cargarSbs2024 = useCallback(async () => {
    const { data } = await api.post('/comisiones-afp/cargar-sbs');
    setAfps(data.afps);
    return data;
  }, []);

  const fetchHistorial = useCallback(async (afpId) => {
    const { data } = await api.get(`/comisiones-afp/${afpId}/historial`);
    return data.data;
  }, []);

  return {
    afps,
    loading,
    fetchComisiones,
    crearComision,
    actualizarComision,
    eliminarComision,
    cargarSbs2024,
    fetchHistorial,
  };
}
