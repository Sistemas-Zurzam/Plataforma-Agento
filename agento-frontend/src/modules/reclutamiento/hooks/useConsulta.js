import { useCallback, useEffect, useState } from 'react';

/**
 * Estado de carga/error/datos para una consulta. `cargar` (memoizada con
 * useCallback por el hook que la usa) se vuelve a ejecutar cuando cambia o
 * al llamar a `recargar`. "cargando" se deriva de si la última respuesta
 * corresponde a la consulta vigente; las respuestas de consultas ya
 * reemplazadas se ignoran.
 */
export function useConsulta(cargar, { inmediata = true } = {}) {
  const [version, setVersion] = useState(0);
  const [estado, setEstado] = useState({ cargar: null, version: -1, datos: null, error: null });

  useEffect(() => {
    if (!inmediata) {
      return undefined;
    }
    let vigente = true;
    cargar().then(
      (datos) => {
        if (vigente) setEstado({ cargar, version, datos, error: null });
      },
      (error) => {
        if (vigente) setEstado((previo) => ({ cargar, version, datos: previo.datos, error }));
      },
    );
    return () => {
      vigente = false;
    };
  }, [cargar, version, inmediata]);

  const cargando = inmediata && (estado.cargar !== cargar || estado.version !== version);
  const recargar = useCallback(() => setVersion((v) => v + 1), []);
  const setDatos = useCallback((valor) => setEstado((previo) => ({
    ...previo,
    datos: typeof valor === 'function' ? valor(previo.datos) : valor,
  })), []);

  return { datos: estado.datos, setDatos, cargando, error: cargando ? null : estado.error, recargar };
}
