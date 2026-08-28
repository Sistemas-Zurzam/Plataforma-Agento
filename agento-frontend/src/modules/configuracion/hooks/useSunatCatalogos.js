import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useSunatCatalogos() {
  const [resumen, setResumen] = useState(null);
  const [resumenLoading, setResumenLoading] = useState(false);

  const [mapeos, setMapeos] = useState([]);
  const [mapeosLoading, setMapeosLoading] = useState(false);

  const [tiposAusencia, setTiposAusencia] = useState([]);
  const [tiposAusenciaLoading, setTiposAusenciaLoading] = useState(false);

  const fetchResumen = useCallback(async () => {
    setResumenLoading(true);
    try {
      const { data } = await api.get('/sunat/resumen');
      setResumen(data);
      return data;
    } finally {
      setResumenLoading(false);
    }
  }, []);

  const fetchMapeos = useCallback(async (tipo) => {
    setMapeosLoading(true);
    try {
      const { data } = await api.get('/sunat/mapeos', { params: { tipo } });
      setMapeos(data.data);
      return data.data;
    } finally {
      setMapeosLoading(false);
    }
  }, []);

  const actualizarMapeo = useCallback(async (mapeoId, valores) => {
    const { data } = await api.put(`/sunat/mapeos/${mapeoId}`, valores);
    setMapeos((actuales) => actuales.map((m) => (m.id === mapeoId ? data.data : m)));
    return data.data;
  }, []);

  const fetchTiposAusencia = useCallback(async () => {
    setTiposAusenciaLoading(true);
    try {
      const { data } = await api.get('/tipos-ausencia');
      setTiposAusencia(data.data);
      return data.data;
    } finally {
      setTiposAusenciaLoading(false);
    }
  }, []);

  const actualizarTipoAusencia = useCallback(async (tipoAusenciaId, valores) => {
    const { data } = await api.put(`/tipos-ausencia/${tipoAusenciaId}/codigo-sunat`, valores);
    setTiposAusencia((actuales) => actuales.map((t) => (t.id === tipoAusenciaId ? data.data : t)));
    return data.data;
  }, []);

  return {
    resumen,
    resumenLoading,
    fetchResumen,
    mapeos,
    mapeosLoading,
    fetchMapeos,
    actualizarMapeo,
    tiposAusencia,
    tiposAusenciaLoading,
    fetchTiposAusencia,
    actualizarTipoAusencia,
  };
}
