import { App, Select } from 'antd';
import { useEffect } from 'react';
import { useEmpresas } from '../hooks/useEmpresas';

/**
 * Selector de empresa activa para usar dentro del área de filtros de una
 * vista (junto a búsqueda/estado), además del que ya vive en el Header
 * global. Mismo mecanismo de cambio de empresa que Header.jsx.
 */
export default function EmpresaActivaFiltro({ user, onUserRefresh }) {
  const { message } = App.useApp();
  const { empresas, fetchEmpresas, activarEmpresa } = useEmpresas();

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const handleChange = async (empresaId) => {
    if (empresaId === user?.empresa?.id) return;

    try {
      await activarEmpresa(empresaId);
      await Promise.all([fetchEmpresas(), onUserRefresh?.()]);
    } catch {
      message.error('No se pudo cambiar de empresa');
    }
  };

  return (
    <Select
      value={user?.empresa?.id}
      onChange={handleChange}
      className="w-56"
      showSearch
      optionFilterProp="label"
      options={empresas
        .filter((empresa) => empresa.activa)
        .map((empresa) => ({ value: empresa.id, label: empresa.nombre }))}
    />
  );
}
