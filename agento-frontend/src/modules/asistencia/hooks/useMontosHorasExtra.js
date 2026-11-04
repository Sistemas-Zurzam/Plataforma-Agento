import { useEffect, useState } from 'react';
import api from '../../../services/api';

// El backend acepta hasta 200 ids por consulta.
const MAXIMO_IDS = 200;

/**
 * Montos referenciales de horas extra (Nóminas). Solo se consultan si el
 * usuario puede ver nóminas; devuelve { [id]: { monto, minutos, base, tasa, nota } }.
 */
export default function useMontosHorasExtra(ids, habilitado) {
  const clave = habilitado ? ids.slice(0, MAXIMO_IDS).join(',') : '';
  const [resultado, setResultado] = useState({ clave: '', montos: {} });

  useEffect(() => {
    if (!clave) return undefined;
    let vigente = true;
    api.get('/nominas/horas-extra/montos-aproximados', { params: { ids: clave.split(',') } })
      .then(({ data }) => { if (vigente) setResultado({ clave, montos: data.data ?? {} }); })
      .catch(() => { if (vigente) setResultado({ clave, montos: {} }); });
    return () => { vigente = false; };
  }, [clave]);

  return {
    montos: resultado.clave === clave ? resultado.montos : {},
    cargando: Boolean(clave) && resultado.clave !== clave,
  };
}
