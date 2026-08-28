import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useBancos() {
  const [bancos, setBancos] = useState([]);

  const fetchBancos = useCallback(async () => {
    const { data } = await api.get('/bancos');
    setBancos(data.data);
    return data.data;
  }, []);

  return { bancos, fetchBancos };
}
