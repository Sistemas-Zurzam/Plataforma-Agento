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

  const actualizarCiclo = useCallback(async (cicloId, values) => {
    const { data } = await api.put(`/ciclos-remunerativos/${cicloId}`, values);
    return data.data;
  }, []);

  const eliminarCiclo = useCallback(async (cicloId) => {
    await api.delete(`/ciclos-remunerativos/${cicloId}`);
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

  const fetchIncidenciasPendientesCierre = useCallback(async (cicloId) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/incidencias-pendientes-cierre`);
    return data.data;
  }, []);

  const resolverIncidenciaPendiente = useCallback(async (cicloId, incidenciaId, accion, motivo) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/incidencias-pendientes-cierre/${incidenciaId}`, { accion, motivo });
    return data;
  }, []);

  const reabrirCiclo = useCallback(async (cicloId) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/reabrir`);
    return data.data;
  }, []);

  const marcarCicloPagado = useCallback(async (cicloId) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/marcar-pagado`);
    return data.data;
  }, []);

  const fetchBoletas = useCallback(async (cicloId, page = 1, perPage = 25, tipo = null, busqueda = null) => {
    setBoletasLoading(true);
    try {
      const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/boletas`, {
        params: { page, per_page: perPage, tipo: tipo || undefined, busqueda: busqueda || undefined },
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

  const fetchBoletasExportablesIds = useCallback(async (cicloId, tipo = null, busqueda = null) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/boletas-exportables/ids`, {
      params: { tipo: tipo || undefined, busqueda: busqueda || undefined },
    });
    return data.data;
  }, []);

  const fetchResumen = useCallback(async (cicloId, tipo = null, busqueda = null) => {
    setResumenLoading(true);
    try {
      const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/resumen`, {
        params: { tipo: tipo || undefined, busqueda: busqueda || undefined },
      });
      setResumen(data);
      return data;
    } finally {
      setResumenLoading(false);
    }
  }, []);

  const fetchResumenContable = useCallback(async (params) => {
    const { data } = await api.get('/ciclos-remunerativos-resumen-contable', { params });
    return data;
  }, []);

  const verBoleta = useCallback(async (boletaId) => {
    const { data } = await api.get(`/boletas/${boletaId}`);
    return data.data;
  }, []);

  const guardarComprobanteRh = useCallback(async (boletaId, valores) => {
    const { data } = await api.patch(`/boletas/${boletaId}/comprobante-rh`, valores);
    return data.data;
  }, []);

  const aprobarBoleta = useCallback(async (boletaId) => {
    const { data } = await api.patch(`/boletas/${boletaId}/aprobar`);
    return data.data;
  }, []);

  const fetchIncidenciasPendientesAprobar = useCallback(async (boletaId) => {
    const { data } = await api.get(`/boletas/${boletaId}/incidencias-pendientes-aprobar`);
    return data.data;
  }, []);

  const aprobarBoletasMasivo = useCallback(async (cicloId, boletaIds) => {
    const { data } = await api.patch(`/ciclos-remunerativos/${cicloId}/boletas/aprobar-masivo`, { ids: boletaIds });
    return data;
  }, []);

  const fetchIncidenciasPendientesAprobarMasivo = useCallback(async (cicloId, boletaIds) => {
    const { data } = await api.post(`/ciclos-remunerativos/${cicloId}/boletas/incidencias-pendientes-aprobar-masivo`, { ids: boletaIds });
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

  const actualizarConceptoPeriodo = useCallback(async (cicloId, colaboradorId, conceptoPeriodoId, values) => {
    const { data } = await api.put(`/ciclos-remunerativos/${cicloId}/colaboradores/${colaboradorId}/conceptos/${conceptoPeriodoId}`, values);
    return data.data;
  }, []);

  const eliminarConceptoPeriodo = useCallback(async (cicloId, colaboradorId, conceptoPeriodoId) => {
    await api.delete(`/ciclos-remunerativos/${cicloId}/colaboradores/${colaboradorId}/conceptos/${conceptoPeriodoId}`);
  }, []);

  const fetchPlameValidacion = useCallback(async (cicloId) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/plame-validacion`);
    return data;
  }, []);

  /**
   * Los 3 endpoints de exportación PLAME responden de dos formas distintas
   * según el caso (Sección 52 del encargo): un ZIP binario cuando se pudo
   * generar, o un JSON de error/estado estructurado (ciclo no pagado,
   * archivos bloqueados, sin registros aplicables) — nunca un 500. Como
   * axios necesita `responseType: 'blob'` para poder recibir el ZIP, el
   * caso JSON llega igual como Blob y hay que decodificarlo a mano
   * mirando el Content-Type real de la respuesta.
   */
  const exportarPlame = useCallback(async (cicloId, tipo) => {
    let response;
    try {
      response = await api.post(`/ciclos-remunerativos/${cicloId}/plame/exportar/${tipo}`, {}, { responseType: 'blob' });
    } catch (err) {
      if (err.response) {
        response = err.response;
      } else {
        throw err;
      }
    }

    const contentType = response.headers?.['content-type'] ?? '';
    if (contentType.includes('application/json')) {
      const texto = await response.data.text();
      return { descargado: false, ...JSON.parse(texto) };
    }

    const disposicion = response.headers?.['content-disposition'] ?? '';
    const nombreArchivo = disposicion.match(/filename="?([^"]+)"?/)?.[1] ?? `PLAME_${tipo}.zip`;

    const url = window.URL.createObjectURL(response.data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombreArchivo;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.URL.revokeObjectURL(url);

    return { descargado: true };
  }, []);

  const fetchAfpNetValidacion = useCallback(async (cicloId) => {
    const { data } = await api.get(`/ciclos-remunerativos/${cicloId}/afpnet-validacion`);
    return data;
  }, []);

  /**
   * Completamente independiente de exportarPlame() (Sección 3 del encargo
   * AFPnet: AFPnet no comparte código con PLAME) — misma estrategia de
   * decodificar ZIP/archivo binario vs. JSON de error mirando el
   * Content-Type real, pero sin ningún import compartido.
   */
  const exportarAfpNet = useCallback(async (cicloId, formato) => {
    let response;
    try {
      response = await api.post(`/ciclos-remunerativos/${cicloId}/afpnet/exportar/${formato}`, {}, { responseType: 'blob' });
    } catch (err) {
      if (err.response) {
        response = err.response;
      } else {
        throw err;
      }
    }

    const contentType = response.headers?.['content-type'] ?? '';
    if (contentType.includes('application/json')) {
      const texto = await response.data.text();
      return { descargado: false, ...JSON.parse(texto) };
    }

    const disposicion = response.headers?.['content-disposition'] ?? '';
    const extension = formato === 'excel' ? 'xlsx' : 'txt';
    const nombreArchivo = disposicion.match(/filename="?([^"]+)"?/)?.[1] ?? `AFPnet.${extension}`;

    const url = window.URL.createObjectURL(response.data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombreArchivo;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.URL.revokeObjectURL(url);

    return { descargado: true };
  }, []);

  /**
   * Completamente independiente de PLAME/AFPnet (Sección 3 del encargo
   * Telecrédito: ni un import ni una función compartida) — la validación
   * es POST porque depende de parámetros (cuenta de cargo, fecha de
   * proceso, subtipo), a diferencia de PLAME/AFPnet que no necesitan
   * ninguno.
   */
  const fetchTelecreditoBcpValidacion = useCallback(async (cicloId, parametros) => {
    const { data } = await api.post(`/ciclos-remunerativos/${cicloId}/telecredito-bcp/validacion`, parametros);
    return data;
  }, []);

  const exportarTelecreditoBcp = useCallback(async (cicloId, parametros) => {
    let response;
    try {
      response = await api.post(`/ciclos-remunerativos/${cicloId}/telecredito-bcp/exportar`, parametros, { responseType: 'blob' });
    } catch (err) {
      if (err.response) {
        response = err.response;
      } else {
        throw err;
      }
    }

    const contentType = response.headers?.['content-type'] ?? '';
    if (contentType.includes('application/json')) {
      const texto = await response.data.text();
      return { descargado: false, ...JSON.parse(texto) };
    }

    const disposicion = response.headers?.['content-disposition'] ?? '';
    const nombreArchivo = disposicion.match(/filename="?([^"]+)"?/)?.[1] ?? 'TELECREDITO_BCP.txt';

    const url = window.URL.createObjectURL(response.data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombreArchivo;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.URL.revokeObjectURL(url);

    return { descargado: true };
  }, []);

  /**
   * BBVA Net Cash — completamente independiente de Telecrédito BCP (ni un
   * import ni una función compartida). A diferencia de Telecrédito, el
   * único parámetro operativo es `subtipo`: el backend resuelve solo la
   * cuenta de cargo y la fecha de proceso desde la empresa/ciclo.
   */
  const fetchBbvaNetCashValidacion = useCallback(async (cicloId, parametros) => {
    const { data } = await api.post(`/ciclos-remunerativos/${cicloId}/bbva-netcash/validacion`, parametros);
    return data;
  }, []);

  const exportarBbvaNetCash = useCallback(async (cicloId, parametros) => {
    let response;
    try {
      response = await api.post(`/ciclos-remunerativos/${cicloId}/bbva-netcash/exportar`, parametros, { responseType: 'blob' });
    } catch (err) {
      if (err.response) {
        response = err.response;
      } else {
        throw err;
      }
    }

    const contentType = response.headers?.['content-type'] ?? '';
    if (contentType.includes('application/json')) {
      const texto = await response.data.text();
      return { descargado: false, ...JSON.parse(texto) };
    }

    const disposicion = response.headers?.['content-disposition'] ?? '';
    const nombreArchivo = disposicion.match(/filename="?([^"]+)"?/)?.[1] ?? 'BBVA_NETCASH.txt';

    const url = window.URL.createObjectURL(response.data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = nombreArchivo;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.URL.revokeObjectURL(url);

    return { descargado: true };
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
    actualizarCiclo,
    eliminarCiclo,
    calcularPlanilla,
    fetchEstadoCalculo,
    cerrarCiclo,
    fetchIncidenciasPendientesCierre,
    resolverIncidenciaPendiente,
    reabrirCiclo,
    marcarCicloPagado,
    boletas,
    boletasLoading,
    pagination,
    fetchBoletas,
    fetchBoletasExportablesIds,
    resumen,
    resumenLoading,
    fetchResumen,
    fetchResumenContable,
    verBoleta,
    aprobarBoleta,
    fetchIncidenciasPendientesAprobar,
    aprobarBoletasMasivo,
    fetchIncidenciasPendientesAprobarMasivo,
    pagarBoleta,
    guardarComprobanteRh,
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
    actualizarConceptoPeriodo,
    eliminarConceptoPeriodo,
    previsualizacion,
    previsualizacionLoading,
    fetchPrevisualizacion,
    fetchPlameValidacion,
    exportarPlame,
    fetchAfpNetValidacion,
    exportarAfpNet,
    fetchTelecreditoBcpValidacion,
    exportarTelecreditoBcp,
    fetchBbvaNetCashValidacion,
    exportarBbvaNetCash,
  };
}
