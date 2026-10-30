import { useCallback, useState } from 'react';

const leerQuery = () => Object.fromEntries(new URLSearchParams(window.location.search));

/**
 * Filtros de un listado del portal sincronizados con el query string: un
 * enlace de la mesa de trabajo abre el listado ya filtrado, y recargar o
 * volver atrás conserva los filtros. Solo se leen claves declaradas en
 * `porDefecto`; el backend vuelve a validar todo.
 */
export function useFiltrosUrl(porDefecto) {
  const [filtros, setFiltrosEstado] = useState(() => {
    const query = leerQuery();
    const iniciales = { ...porDefecto };
    Object.keys(porDefecto).forEach((clave) => {
      if (query[clave] !== undefined && query[clave] !== '') {
        iniciales[clave] = typeof porDefecto[clave] === 'number' ? Number(query[clave]) : query[clave];
      }
    });
    return iniciales;
  });

  const setFiltros = useCallback((cambios) => {
    setFiltrosEstado((previos) => {
      const siguientes = { ...previos, ...(typeof cambios === 'function' ? cambios(previos) : cambios) };
      const query = new URLSearchParams();
      Object.entries(siguientes).forEach(([clave, valor]) => {
        if (valor !== undefined && valor !== null && valor !== '') query.set(clave, String(valor));
      });
      const texto = query.toString();
      window.history.replaceState(window.history.state, '', `${window.location.pathname}${texto ? `?${texto}` : ''}`);
      return siguientes;
    });
  }, []);

  return [filtros, setFiltros];
}
