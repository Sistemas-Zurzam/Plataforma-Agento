import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useUsuarios() {
  const [usuarios, setUsuarios] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 10, total: 0 });

  const fetchUsuarios = useCallback(async (page = 1, perPage = 10) => {
    setLoading(true);
    try {
      const { data } = await api.get('/usuarios', {
        params: { page, per_page: perPage },
      });
      setUsuarios(data.data);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearUsuario = useCallback(async (values) => {
    const { data } = await api.post('/usuarios', values);
    return data.data;
  }, []);

  const actualizarUsuario = useCallback(async (usuarioId, values) => {
    const { data } = await api.put(`/usuarios/${usuarioId}`, values);
    return data.data;
  }, []);

  const cambiarRol = useCallback(async (usuarioId, roleId) => {
    const { data } = await api.patch(`/usuarios/${usuarioId}/rol`, {
      role_id: roleId,
    });
    return data.data;
  }, []);

  const eliminarUsuario = useCallback(async (usuarioId) => {
    await api.delete(`/usuarios/${usuarioId}`);
  }, []);

  return {
    usuarios,
    loading,
    pagination,
    fetchUsuarios,
    crearUsuario,
    actualizarUsuario,
    cambiarRol,
    eliminarUsuario,
  };
}
