import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useRoles() {
  const [roles, setRoles] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchRoles = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/roles');
      setRoles(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearRol = useCallback(async (nombre) => {
    const { data } = await api.post('/roles', { nombre });
    return data.data;
  }, []);

  const actualizarRol = useCallback(async (roleId, nombre) => {
    const { data } = await api.put(`/roles/${roleId}`, { nombre });
    return data.data;
  }, []);

  const eliminarRol = useCallback(async (roleId) => {
    await api.delete(`/roles/${roleId}`);
  }, []);

  return { roles, loading, fetchRoles, crearRol, actualizarRol, eliminarRol };
}
