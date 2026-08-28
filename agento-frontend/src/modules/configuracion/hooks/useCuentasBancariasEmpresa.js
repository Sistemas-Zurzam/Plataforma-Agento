import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useCuentasBancariasEmpresa(empresaId) {
  const [cuentas, setCuentas] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchCuentas = useCallback(async () => {
    if (!empresaId) {
      setCuentas([]);
      return [];
    }
    setLoading(true);
    try {
      const { data } = await api.get(`/empresas/${empresaId}/cuentas-bancarias`);
      setCuentas(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, [empresaId]);

  const crearCuenta = useCallback(
    async (values) => {
      const { data } = await api.post(`/empresas/${empresaId}/cuentas-bancarias`, values);
      setCuentas((prev) => [...prev, data.data]);
      return data.data;
    },
    [empresaId],
  );

  const actualizarCuenta = useCallback(
    async (cuentaId, values) => {
      const { data } = await api.put(`/empresas/${empresaId}/cuentas-bancarias/${cuentaId}`, values);
      setCuentas((prev) => prev.map((c) => (c.id === cuentaId ? data.data : c)));
      return data.data;
    },
    [empresaId],
  );

  const actualizarEstadoCuenta = useCallback(
    async (cuentaId, activo) => {
      const { data } = await api.patch(`/empresas/${empresaId}/cuentas-bancarias/${cuentaId}/estado`, { activo });
      setCuentas((prev) => prev.map((c) => (c.id === cuentaId ? data.data : c)));
      return data.data;
    },
    [empresaId],
  );

  return { cuentas, loading, fetchCuentas, crearCuenta, actualizarCuenta, actualizarEstadoCuenta };
}
