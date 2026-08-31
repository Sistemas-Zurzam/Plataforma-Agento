import { useCallback, useRef, useState } from 'react';
import api from '../../../services/api';

export function useColaboradores() {
  const [colaboradores, setColaboradores] = useState([]);
  const [stats, setStats] = useState({ total: 0, activos: 0 });
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 10, total: 0 });
  const solicitudActualRef = useRef(0);

  const fetchColaboradores = useCallback(async (page = 1, perPage = 10, busqueda = '', todasEmpresas = false) => {
    const idSolicitud = ++solicitudActualRef.current;
    setLoading(true);
    try {
      const { data } = await api.get('/colaboradores', {
        params: {
          page,
          per_page: perPage,
          busqueda: busqueda || undefined,
          todas_empresas: todasEmpresas || undefined,
        },
      });
      // Si mientras esta solicitud viajaba se disparó otra más nueva (ej. el
      // usuario cambió de empresa o escribió otra letra en el buscador), esa
      // respuesta puede llegar antes que esta — sin este guard, la más
      // vieja pisaba igual el estado al llegar después y la tabla mostraba
      // los datos de la empresa/búsqueda anterior.
      if (idSolicitud !== solicitudActualRef.current) {
        return data.data;
      }
      setColaboradores(data.data);
      setStats(data.stats);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
      return data.data;
    } finally {
      if (idSolicitud === solicitudActualRef.current) {
        setLoading(false);
      }
    }
  }, []);

  const crearColaborador = useCallback(async (values) => {
    const { data } = await api.post('/colaboradores', values);
    return data.data;
  }, []);

  const restaurarColaborador = useCallback(async (colaboradorId) => {
    const { data } = await api.patch(`/colaboradores/${colaboradorId}/restaurar`);
    return data.data;
  }, []);

  const fetchCalendarioDefecto = useCallback(async (horarioId, fechaIngreso) => {
    const { data } = await api.get('/colaboradores/calendario-defecto', {
      params: { horario_id: horarioId, fecha_ingreso: fechaIngreso },
    });
    return data.dias;
  }, []);

  const fetchRotativosSinRol = useCallback(async (anio, mes, todasEmpresas = false) => {
    const { data } = await api.get('/colaboradores/rotativos-sin-rol', {
      params: { anio, mes, todas_empresas: todasEmpresas || undefined },
    });
    return data.data;
  }, []);

  const fetchCalendarioDelMes = useCallback(async (colaboradorId, anio, mes) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}/calendario`, {
      params: { anio, mes },
    });
    return data.dias;
  }, []);

  const fetchColaborador = useCallback(async (colaboradorId) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}`);
    return data.data;
  }, []);

  const actualizarCalendario = useCallback(async (colaboradorId, dias) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/calendario`, { dias });
    return data.data;
  }, []);

  const actualizarHorario = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/horario`, values);
    return data.data;
  }, []);

  const actualizarColaborador = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}`, values);
    return data.data;
  }, []);

  // V3 P4/P5 — mismo endpoint que ya usa ConfiguracionNominaModal desde
  // Remuneraciones (ColaboradorController::actualizarConfiguracionNomina) —
  // se replica acá para que Personas no dependa de un hook de otro módulo,
  // pero sin duplicar ninguna lógica de negocio (vive toda en el backend).
  const actualizarConfiguracionNomina = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/configuracion-nomina`, values);
    return data.data;
  }, []);

  const actualizarRemuneracion = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/remuneracion`, values);
    return data.data;
  }, []);

  const cesarColaborador = useCallback(async (colaboradorId, values) => {
    const { data } = await api.patch(`/colaboradores/${colaboradorId}/cesar`, values);
    return data.data;
  }, []);

  const previsualizarLiquidacionCese = useCallback(async (colaboradorId, values) => {
    const params = Object.fromEntries(Object.entries(values).map(([key, value]) => [
      key,
      typeof value === 'boolean' ? (value ? 1 : 0) : value,
    ]));
    const { data } = await api.get(`/colaboradores/${colaboradorId}/liquidacion-cese/previsualizar`, { params });
    return data.data;
  }, []);

  const eliminarColaborador = useCallback(async (colaboradorId) => {
    await api.delete(`/colaboradores/${colaboradorId}`);
  }, []);

  const listarVacacionMovimientos = useCallback(async (colaboradorId) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}/vacacion-movimientos`);
    return data.data;
  }, []);

  const crearVacacionMovimiento = useCallback(async (colaboradorId, values) => {
    const { data } = await api.post(`/colaboradores/${colaboradorId}/vacacion-movimientos`, values);
    return data.data;
  }, []);

  const eliminarVacacionMovimiento = useCallback(async (colaboradorId, movimientoId) => {
    await api.delete(`/colaboradores/${colaboradorId}/vacacion-movimientos/${movimientoId}`);
  }, []);

  const subirDocumento = useCallback(async (colaboradorId, tipo, archivo) => {
    const formData = new FormData();
    formData.append('tipo', tipo);
    formData.append('archivo', archivo);
    const { data } = await api.post(`/colaboradores/${colaboradorId}/documentos`, formData);
    return data.data;
  }, []);

  const verDocumento = useCallback(async (colaboradorId, documentoId) => {
    const { data } = await api.get(`/colaboradores/${colaboradorId}/documentos/${documentoId}`, {
      responseType: 'blob',
    });
    return data;
  }, []);

  const subirFotoPerfil = useCallback(async (colaboradorId, archivo) => {
    const formData = new FormData();
    formData.append('archivo', archivo);
    const { data } = await api.post(`/colaboradores/${colaboradorId}/foto-perfil`, formData);
    return data.data;
  }, []);

  const fetchFotoPerfil = useCallback(async (colaboradorId) => {
    try {
      const { data } = await api.get(`/colaboradores/${colaboradorId}/foto-perfil`, {
        responseType: 'blob',
      });
      return data;
    } catch (err) {
      if (err.response?.status === 404) return null;
      throw err;
    }
  }, []);

  const descargarPlantilla = useCallback(async () => {
    const { data } = await api.get('/colaboradores/plantilla-importacion', { responseType: 'blob' });
    const url = window.URL.createObjectURL(data);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = 'plantilla-colaboradores.xlsx';
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.URL.revokeObjectURL(url);
  }, []);

  const previsualizarImportacion = useCallback(async (archivo) => {
    const formulario = new FormData();
    formulario.append('archivo', archivo);
    const { data } = await api.post('/colaboradores/importar/previsualizar', formulario, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.data;
  }, []);

  const confirmarImportacion = useCallback(async (archivo) => {
    const formulario = new FormData();
    formulario.append('archivo', archivo);
    const { data } = await api.post('/colaboradores/importar', formulario, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.data;
  }, []);

  return {
    colaboradores,
    stats,
    loading,
    pagination,
    fetchColaboradores,
    crearColaborador,
    restaurarColaborador,
    fetchCalendarioDefecto,
    fetchRotativosSinRol,
    fetchCalendarioDelMes,
    fetchColaborador,
    actualizarCalendario,
    actualizarHorario,
    actualizarColaborador,
    actualizarConfiguracionNomina,
    actualizarRemuneracion,
    cesarColaborador,
    previsualizarLiquidacionCese,
    eliminarColaborador,
    listarVacacionMovimientos,
    crearVacacionMovimiento,
    eliminarVacacionMovimiento,
    subirDocumento,
    verDocumento,
    subirFotoPerfil,
    fetchFotoPerfil,
    descargarPlantilla,
    previsualizarImportacion,
    confirmarImportacion,
  };
}
