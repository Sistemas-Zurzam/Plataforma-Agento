import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function usePermisos() {
  const [roles, setRoles] = useState([]);
  const [grupos, setGrupos] = useState({});
  const [loading, setLoading] = useState(false);

  const fetchPermisos = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/permisos');
      setRoles(data.roles);
      setGrupos(data.grupos);
      return data;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * @param {Record<number, number[]>} asignaciones role_id -> [permission_id, ...]
   */
  const guardarCambios = useCallback(async (asignaciones) => {
    const { data } = await api.put('/permisos', { asignaciones });
    setRoles(data.roles);
    setGrupos(data.grupos);
    return data;
  }, []);

  return { roles, grupos, loading, fetchPermisos, guardarCambios };
}
