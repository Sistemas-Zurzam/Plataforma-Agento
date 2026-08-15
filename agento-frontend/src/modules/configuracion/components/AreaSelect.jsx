import { Select } from 'antd';
import { useEffect, useState } from 'react';
import { useAreas } from '../hooks/useAreas';

const CREAR_AREA = '__crear_area__';

/**
 * Área picker that doubles as "buscar o crear": typing a name that doesn't
 * match an existing area (case/whitespace-insensitively) offers to create
 * it. Remounted by the parent via `key={empresaId}` whenever the selected
 * empresa changes, so its local search state resets for free instead of
 * being cleared from an effect.
 */
export default function AreaSelect({
  empresaId,
  value,
  onChange,
  disabled,
  onCreateError,
  puedeCrear = false,
}) {
  const { areas, loading, fetchAreas, createArea } = useAreas(empresaId);
  const [areaSearch, setAreaSearch] = useState('');
  const [creandoArea, setCreandoArea] = useState(false);

  useEffect(() => {
    if (empresaId) {
      fetchAreas();
    }
  }, [empresaId, fetchAreas]);

  const busquedaNormalizada = areaSearch.trim().toLowerCase();
  const coincideExistente = areas.some(
    (area) => area.nombre.trim().toLowerCase() === busquedaNormalizada,
  );

  const areasFiltradas = busquedaNormalizada
    ? areas.filter((area) => area.nombre.toLowerCase().includes(busquedaNormalizada))
    : areas;

  const options = areasFiltradas.map((area) => ({ value: area.id, label: area.nombre }));
  if (puedeCrear && busquedaNormalizada && !coincideExistente) {
    options.push({
      value: CREAR_AREA,
      label: `+ Crear área "${areaSearch.trim()}"`,
    });
  }

  const handleChange = async (selected) => {
    if (selected !== CREAR_AREA || !puedeCrear) {
      onChange(selected ?? undefined);
      return;
    }

    setCreandoArea(true);
    try {
      const nuevaArea = await createArea(areaSearch.trim());
      onChange(nuevaArea.id);
      setAreaSearch('');
    } catch (err) {
      const mensaje =
        err.response?.data?.errors?.nombre?.[0] ??
        err.response?.data?.message ??
        'No se pudo crear el área';
      onCreateError?.(mensaje);
    } finally {
      setCreandoArea(false);
    }
  };

  return (
    <Select
      placeholder={empresaId ? 'Buscar o crear un área' : 'Selecciona primero una empresa'}
      allowClear
      showSearch
      value={value}
      searchValue={areaSearch}
      onSearch={setAreaSearch}
      onChange={handleChange}
      filterOption={false}
      loading={loading || creandoArea}
      disabled={disabled}
      notFoundContent={
        loading ? 'Buscando…' : puedeCrear ? 'Escribe para crear un área' : 'Sin áreas disponibles'
      }
      options={options}
    />
  );
}
