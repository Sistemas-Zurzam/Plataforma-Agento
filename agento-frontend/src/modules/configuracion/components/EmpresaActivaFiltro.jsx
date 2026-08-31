import { App, Select } from 'antd';
import { useEffect, useState } from 'react';
import { useEmpresas } from '../hooks/useEmpresas';

/**
 * Selector de empresa activa para usar dentro del área de filtros de una
 * vista (junto a búsqueda/estado), además del que ya vive en el Header
 * global. Mismo mecanismo de cambio de empresa que Header.jsx.
 */
export default function EmpresaActivaFiltro({ user, onUserRefresh }) {
  const { message } = App.useApp();
  const { empresas, fetchEmpresas, activarEmpresa } = useEmpresas();
  const [switching, setSwitching] = useState(false);

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const handleChange = async (empresaId) => {
    // Bloquea selecciones mientras la anterior sigue en vuelo — dos cambios
    // de empresa disparados casi juntos podían resolverse en cualquier
    // orden (activar B, activar C, pero la respuesta de B llega última) y
    // dejaban al usuario viendo la empresa equivocada o el selector vacío.
    if (switching || empresaId === user?.empresa?.id) return;

    setSwitching(true);
    try {
      await activarEmpresa(empresaId);
      await Promise.all([fetchEmpresas(), onUserRefresh?.()]);
    } catch {
      message.error('No se pudo cambiar de empresa');
    } finally {
      setSwitching(false);
    }
  };

  return (
    <Select
      value={user?.empresa?.id}
      onChange={handleChange}
      loading={switching}
      disabled={switching}
      className="w-56"
      showSearch
      optionFilterProp="label"
      options={empresas
        .filter((empresa) => empresa.activa)
        .map((empresa) => ({ value: empresa.id, label: empresa.nombre_comercial }))}
    />
  );
}
