import { useCallback, useState } from 'react';
import api from '../services/api';

export function useUpdateProfile() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const updateProfile = useCallback(async (values) => {
    setLoading(true);
    setError(null);

    try {
      const { data } = await api.put('/me', values);
      return data.data;
    } catch (err) {
      const message =
        err.response?.data?.message ?? 'No se pudo actualizar el perfil';
      setError(message);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { updateProfile, loading, error };
}
