import { useCallback, useState } from 'react';
import api from '../services/api';

export function useAuth() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const login = useCallback(async ({ login: identificador, password }) => {
    setLoading(true);
    setError(null);

    try {
      const { data } = await api.post('/login', { login: identificador, password });
      localStorage.setItem('access_token', data.access_token);
      return true;
    } catch (err) {
      setError(err.response?.data?.message ?? 'No se pudo iniciar sesión');
      return false;
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } finally {
      localStorage.removeItem('access_token');
    }
  }, []);

  return { login, logout, loading, error };
}
