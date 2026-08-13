import { useCallback, useState } from 'react';
import api from '../services/api';

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

  return { roles, loading, fetchRoles };
}
