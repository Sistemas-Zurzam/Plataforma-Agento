import { useCallback, useState } from 'react';
import api from '../services/api';

export function useUsuarios() {
  const [usuarios, setUsuarios] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchUsuarios = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/usuarios');
      setUsuarios(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearUsuario = useCallback(async (values) => {
    const { data } = await api.post('/usuarios', values);
    return data.data;
  }, []);

  const cambiarRol = useCallback(async (usuarioId, roleId) => {
    const { data } = await api.patch(`/usuarios/${usuarioId}/rol`, {
      role_id: roleId,
    });
    setUsuarios((prev) =>
      prev.map((usuario) => (usuario.id === usuarioId ? data.data : usuario)),
    );
    return data.data;
  }, []);

  const eliminarUsuario = useCallback(async (usuarioId) => {
    await api.delete(`/usuarios/${usuarioId}`);
    setUsuarios((prev) => prev.filter((usuario) => usuario.id !== usuarioId));
  }, []);

  return {
    usuarios,
    loading,
    fetchUsuarios,
    crearUsuario,
    cambiarRol,
    eliminarUsuario,
  };
}
