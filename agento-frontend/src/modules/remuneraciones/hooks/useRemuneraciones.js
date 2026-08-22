import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useRemuneraciones() {
  const [ciclos, setCiclos] = useState([]);
  const [ciclosLoading, setCiclosLoading] = useState(false);

  const [boletas, setBoletas] = useState([]);
  const [boletasLoading, setBoletasLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 25, total: 0 });

  const [resumen, setResumen] = useState(null);
  const [resumenLoading, setResumenLoading] = useState(false);

  const [afps, setAfps] = useState([]);
  const [catalogoConceptos, setCatalogoConceptos] = useState([]);

  const [resumenBeneficio, setResumenBeneficio] = useState(null);
  const [resumenBeneficioLoading, setResumenBeneficioLoading] = useState(false);

  const [previsualizacion, setPrevisualizacion] = useState([]);
  const [previsualizacionLoading, setPrevisualizacionLoading] = useState(false);

  const fetchCiclos = useCallback(async () => {
    setCiclosLoading(true);
    try {
      const { data } = await api.get('/ciclos-remunerativos', { params: { per_page: 50 } });
      setCiclos(data.data);
      return data.data;
    } finally {
      setCiclosLoading(false);
    }
  }, []);

  const crearCiclo = useCallback(async (values) => {
    const { data } = await api.post('/ciclos-remunerativos', values);
    return data.data;
  }, []);

  const calcularPlanilla = useCallback(async (cicloId, motivoRecalculo) => {
    const { data } = await api.post(`/ciclos-remunerativos/${cicloId}/calcular`, {
      motivo_recalculo: motivoRecalculo || undefined,
    });
    return data;
  }, []);

  const fetchEstadoCalculo = useCallback(async (cicloId) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/estado-calculo`);
    return data;
  }, []);

  const cerrarCiclo = useCallback(async (cicloId) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/cerrar`);
    return data.data;
  }, []);

  const reabrirCiclo = useCallback(async (cicloId) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/reabrir`);
    return data.data;
  }, []);

  const fetchBoletas = useCallback(async (cicloId, page = 1, perPage = 25, tipo = null) => {
    setBoletasLoading(true);
    try {
      const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/boletas`, {
        params: { page, per_page: perPage, tipo: tipo || undefined },
      });
      setBoletas(data.data);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
      return data.data;
    } finally {
      setBoletasLoading(false);
    }
  }, []);

  const fetchResumen = useCallback(async (cicloId, tipo = null) => {
    setResumenLoading(true);
    try {
      const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/resumen`, {
        params: { tipo: tipo || undefined },
      });
      setResumen(data);
      return data;
    } finally {
      setResumenLoading(false);
    }
  }, []);

  const verBoleta = useCallback(async (boletaId) => {
    const { data } = await api.get(`/boletas/${boletaId}`);
    return data.data;
  }, []);

  const aprobarBoleta = useCallback(async (boletaId) => {
    const { data } = await api.patch(`/boletas/${boletaId}/aprobar`);
    return data.data;
  }, []);

  const pagarBoleta = useCallback(async (boletaId, referenciaPago) => {
    const { data } = await api.patch(`/boletas/${boletaId}/pagar`, { referencia_pago: referenciaPago });
    return data.data;
  }, []);

  const fetchAfps = useCallback(async () => {
    const { data } = await api.get('/afps');
    setAfps(data.data);
    return data.data;
  }, []);

  const fetchCatalogoConceptos = useCallback(async () => {
    const { data } = await api.get('/conceptos-remuneracion');
    setCatalogoConceptos(data.data);
    return data.data;
  }, []);

  const fetchResumenBeneficio = useCallback(async (tipo, anio) => {
    setResumenBeneficioLoading(true);
    try {
      const { data } = await api.get('/beneficios-sociales/resumen', { params: { tipo, anio } });
      setResumenBeneficio(data);
      return data;
    } finally {
      setResumenBeneficioLoading(false);
    }
  }, []);

  const calcularBeneficio = useCallback(async (tipo, anio) => {
    const { data } = await api.post('/beneficios-sociales/calcular', { tipo, anio });
    setResumenBeneficio(data);
    return data;
  }, []);

  const pagarBeneficio = useCallback(async (beneficioId, referenciaPago) => {
    const { data } = await api.patch(`/beneficios-sociales/${beneficioId}/pagar`, { referencia_pago: referenciaPago });
    setResumenBeneficio(data);
    return data;
  }, []);

  const actualizarConfiguracionNomina = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/configuracion-nomina`, values);
    return data.data;
  }, []);

  const fetchConceptosPeriodo = useCallback(async (cicloId, colaboradorId) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/colaboradores/${colaboradorId}/conceptos`);
    return data.data;
  }, []);

  const registrarConceptoPeriodo = useCallback(async (cicloId, colaboradorId, values) => {
    const { data } = await api.post(`/ciclos-remunerativos/${cicloId}/colaboradores/${colaboradorId}/conceptos`, values);
    return data.data;
  }, []);

  /**
   * Previsualización mensual continua — no requiere ciclo (Sección 5/32 de
   * la documentación funcional). Nunca persiste nada en el backend.
   */
  const fetchPrevisualizacion = useCallback(async (anio, mes) => {
    setPrevisualizacionLoading(true);
    try {
      const { data } = await api.get('/planilla/previsualizar', { params: { anio, mes } });
      setPrevisualizacion(data.data);
      return data.data;
    } finally {
      setPrevisualizacionLoading(false);
    }
  }, []);

  return {
    ciclos,
    ciclosLoading,
    fetchCiclos,
    crearCiclo,
    calcularPlanilla,
    fetchEstadoCalculo,
    cerrarCiclo,
    reabrirCiclo,
    boletas,
    boletasLoading,
    pagination,
    fetchBoletas,
    resumen,
    resumenLoading,
    fetchResumen,
    verBoleta,
    aprobarBoleta,
    pagarBoleta,
    afps,
    fetchAfps,
    catalogoConceptos,
    fetchCatalogoConceptos,
    resumenBeneficio,
    resumenBeneficioLoading,
    fetchResumenBeneficio,
    calcularBeneficio,
    pagarBeneficio,
    actualizarConfiguracionNomina,
    fetchConceptosPeriodo,
    registrarConceptoPeriodo,
    previsualizacion,
    previsualizacionLoading,
    fetchPrevisualizacion,
  };
}
