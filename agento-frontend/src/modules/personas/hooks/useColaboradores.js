import { useCallback, useState } from 'react';
import api from '../../../services/api';

export function useColaboradores() {
  const [colaboradores, setColaboradores] = useState([]);
  const [stats, setStats] = useState({ total: 0, activos: 0 });
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 10, total: 0 });

  const fetchColaboradores = useCallback(async (page = 1, perPage = 10, busqueda = '', todasEmpresas = false) => {
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
      setColaboradores(data.data);
      setStats(data.stats);
      setPagination({
        current: data.meta.current_page,
        pageSize: data.meta.per_page,
        total: data.meta.total,
      });
      return data.data;
    } finally {
      setLoading(false);
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

  const actualizarRemuneracion = useCallback(async (colaboradorId, values) => {
    const { data } = await api.put(`/colaboradores/${colaboradorId}/remuneracion`, values);
    return data.data;
  }, []);

  const cesarColaborador = useCallback(async (colaboradorId, values) => {
    const { data } = await api.patch(`/colaboradores/${colaboradorId}/cesar`, values);
    return data.data;
  }, []);

  const eliminarColaborador = useCallback(async (colaboradorId) => {
    await api.delete(`/colaboradores/${colaboradorId}`);
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
    actualizarRemuneracion,
    cesarColaborador,
    eliminarColaborador,
    subirDocumento,
    verDocumento,
    subirFotoPerfil,
    fetchFotoPerfil,
    descargarPlantilla,
    previsualizarImportacion,
    confirmarImportacion,
  };
}
