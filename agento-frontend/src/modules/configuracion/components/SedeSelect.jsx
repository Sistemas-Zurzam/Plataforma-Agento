import { Select } from 'antd';
import { useEffect } from 'react';
import { useSedes } from '../hooks/useSedes';

/**
 * Sede picker de solo lectura (a diferencia de AreaSelect, no ofrece
 * "+ Crear sede" — las sedes se gestionan desde la pantalla de Empresas).
 * Se recomienda remontar con `key={empresaId}` cuando cambie la empresa.
 */
export default function SedeSelect({ empresaId, value, onChange, disabled }) {
  const { sedes, loading, fetchSedes } = useSedes(empresaId, true);

  useEffect(() => {
    if (empresaId) {
      fetchSedes();
    }
  }, [empresaId, fetchSedes]);

  return (
    <Select
      placeholder={empresaId ? 'Selecciona una sede' : 'Selecciona primero una empresa'}
      showSearch
      optionFilterProp="label"
      value={value}
      onChange={onChange}
      loading={loading}
      disabled={disabled}
      notFoundContent={
        loading ? 'Buscando…' : 'Sin sedes disponibles — créalas desde Configuraciones → Gestión de empresas'
      }
      options={sedes.map((sede) => ({ value: sede.id, label: sede.nombre }))}
    />
  );
}
