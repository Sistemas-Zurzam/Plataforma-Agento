import { Select } from 'antd';
import { useColaboradoresEquipo } from '../hooks/usePortalGestion';

/**
 * Colaboradores ACTIVOS del alcance del gerente, tal como los devuelve el
 * backend (el servidor vuelve a validar el id al guardar).
 */
export default function SelectorColaborador({ value, onChange, id, ...props }) {
  const { datos, cargando, error } = useColaboradoresEquipo();

  return (
    <Select
      id={id}
      showSearch
      optionFilterProp="label"
      placeholder="Selecciona un colaborador de tu equipo"
      loading={cargando}
      status={error ? 'error' : undefined}
      notFoundContent={error ? 'No se pudo cargar tu equipo' : 'Sin colaboradores activos en tus áreas'}
      value={value}
      onChange={onChange}
      options={(datos?.data ?? []).map((c) => ({ value: c.id, label: `${c.nombre_completo} · ${c.legajo ?? ''} · ${c.area ?? ''}` }))}
      {...props}
    />
  );
}
