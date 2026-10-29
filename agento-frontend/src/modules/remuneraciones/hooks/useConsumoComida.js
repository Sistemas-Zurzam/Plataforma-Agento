import { useCallback, useState } from 'react';
import { consumoComidaApi } from '../services/consumoComidaApi';

/** Estado del resumen e historial de consumo de comida de un ciclo. */
export function useConsumoComida(cicloId) {
  const [resumen, setResumen] = useState(null);
  const [importaciones, setImportaciones] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cargar = useCallback(async () => {
    if (!cicloId) return;
    setLoading(true);
    setError(null);
    try {
      const data = await consumoComidaApi.resumen(cicloId);
      setResumen(data.resumen);
      setImportaciones(data.importaciones);
    } catch (err) {
      setError(err.response?.data?.message ?? 'No se pudo cargar el consumo de comida del ciclo.');
    } finally {
      setLoading(false);
    }
  }, [cicloId]);

  return { resumen, importaciones, loading, error, cargar };
}

/** Detalle, consumos y previsualización de una importación. */
export function useConsumoComidaImportacion(importacionId) {
  const [datos, setDatos] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cargar = useCallback(async () => {
    if (!importacionId) return;
    setLoading(true);
    setError(null);
    try {
      setDatos(await consumoComidaApi.obtener(importacionId));
    } catch (err) {
      setError(err.response?.data?.message ?? 'No se pudo cargar la importación.');
    } finally {
      setLoading(false);
    }
  }, [importacionId]);

  return { datos, setDatos, loading, error, cargar };
}
