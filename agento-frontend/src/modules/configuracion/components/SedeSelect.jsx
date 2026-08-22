import { Select } from 'antd';
import { useEffect, useState } from 'react';
import { useSedes } from '../hooks/useSedes';

const CREAR_SEDE = '__crear_sede__';

/**
 * Sede picker that doubles as "buscar o crear" (igual que AreaSelect):
 * escribir un nombre que no coincide con ninguna sede activa existente
 * ofrece crearla en el momento, usando la misma API oficial de Sedes de
 * Configuraciones — sin catálogo paralelo. Remontar con `key={empresaId}`
 * cuando cambie la empresa.
 */
export default function SedeSelect({
  empresaId,
  value,
  onChange,
  disabled,
  onCreateError,
  puedeCrear = false,
}) {
  const { sedes, loading, fetchSedes, createSede } = useSedes(empresaId, true);
  const [sedeSearch, setSedeSearch] = useState('');
  const [creandoSede, setCreandoSede] = useState(false);

  useEffect(() => {
    if (empresaId) {
      fetchSedes();
    }
  }, [empresaId, fetchSedes]);

  const busquedaNormalizada = sedeSearch.trim().toLowerCase();
  const coincideExistente = sedes.some(
    (sede) => sede.nombre.trim().toLowerCase() === busquedaNormalizada,
  );

  const sedesFiltradas = busquedaNormalizada
    ? sedes.filter((sede) => sede.nombre.toLowerCase().includes(busquedaNormalizada))
    : sedes;

  const options = sedesFiltradas.map((sede) => ({ value: sede.id, label: sede.nombre }));
  if (puedeCrear && busquedaNormalizada && !coincideExistente) {
    options.push({
      value: CREAR_SEDE,
      label: `+ Crear sede "${sedeSearch.trim()}"`,
    });
  }

  const handleChange = async (selected) => {
    if (selected !== CREAR_SEDE || !puedeCrear) {
      onChange(selected ?? undefined);
      return;
    }

    setCreandoSede(true);
    try {
      const nuevaSede = await createSede({ nombre: sedeSearch.trim() });
      onChange(nuevaSede.id);
      setSedeSearch('');
    } catch (err) {
      const mensaje =
        err.response?.data?.errors?.nombre?.[0] ??
        err.response?.data?.message ??
        'No se pudo crear la sede';
      onCreateError?.(mensaje);
    } finally {
      setCreandoSede(false);
    }
  };

  return (
    <Select
      placeholder={empresaId ? 'Buscar o selecciona una sede' : 'Selecciona primero una empresa'}
      allowClear
      showSearch
      value={value}
      searchValue={sedeSearch}
      onSearch={setSedeSearch}
      onChange={handleChange}
      filterOption={false}
      loading={loading || creandoSede}
      disabled={disabled}
      notFoundContent={
        loading
          ? 'Buscando…'
          : puedeCrear
            ? 'Escribe para crear una sede'
            : 'Sin sedes disponibles — créalas desde Configuraciones → Gestión de empresas'
      }
      options={options}
    />
  );
}
