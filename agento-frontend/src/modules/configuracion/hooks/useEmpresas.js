import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useEmpresas() {
  const [empresas, setEmpresas] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchEmpresas = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/empresas');
      setEmpresas(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const createEmpresa = useCallback(async (values) => {
    const { data } = await api.post('/empresas', values);
    setEmpresas((prev) => [...prev, data.data]);
    return data.data;
  }, []);

  const updateEmpresa = useCallback(async (empresaId, values) => {
    const { data } = await api.put(`/empresas/${empresaId}`, values);
    setEmpresas((prev) =>
      prev.map((empresa) => (empresa.id === empresaId ? data.data : empresa)),
    );
    return data.data;
  }, []);

  const updateEmpresaEstado = useCallback(async (empresaId, activa) => {
    const { data } = await api.patch(`/empresas/${empresaId}/estado`, { activa });
    setEmpresas((prev) =>
      prev.map((empresa) => (empresa.id === empresaId ? data.data : empresa)),
    );
    return data.data;
  }, []);

  const activarEmpresa = useCallback(async (empresaId) => {
    await api.put(`/empresas/${empresaId}/activar`);
  }, []);

  const subirLogoEmpresa = useCallback(async (empresaId, archivo) => {
    const formData = new FormData();
    formData.append('archivo', archivo);
    const { data } = await api.post(`/empresas/${empresaId}/logo`, formData);
    setEmpresas((prev) =>
      prev.map((empresa) => (empresa.id === empresaId ? data.data : empresa)),
    );
    return data.data;
  }, []);

  return {
    empresas,
    loading,
    fetchEmpresas,
    createEmpresa,
    updateEmpresa,
    updateEmpresaEstado,
    activarEmpresa,
    subirLogoEmpresa,
  };
}
