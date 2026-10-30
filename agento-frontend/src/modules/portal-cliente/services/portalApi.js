import api from '../../../services/api';

/**
 * Endpoints del Portal de Empresa. Nunca envía empresa_id, estado, origen ni
 * actor: el backend los resuelve desde el usuario autenticado.
 */
const completo = (respuesta) => respuesta.data;
const datos = (respuesta) => respuesta.data.data;

export const portalApi = {
  inicio: () => api.get('/portal/inicio').then(datos),

  equipo: {
    colaboradores: (params) => api.get('/portal/asistencia/colaboradores', { params }).then(completo),
    mensual: (params) => api.get('/portal/asistencia/equipo-mensual', { params }).then(completo),
    incidencias: (params) => api.get('/portal/asistencia/incidencias', { params }).then(completo),
  },

  horasExtra: {
    listar: (params) => api.get('/portal/asistencia/horas-extra', { params }).then(completo),
    decidir: (id, valores) => api.patch(`/portal/horas-extra/${id}/decision`, valores).then(datos),
  },

  permisos: {
    listar: (params) => api.get('/portal/asistencia/permisos', { params }).then(completo),
    tipos: () => api.get('/portal/permisos/tipos').then(datos),
    solicitar: (valores) => api.post('/portal/permisos', valores).then(datos),
  },

  planificacion: {
    semana: (params) => api.get('/portal/planificacion', { params }).then(datos),
    guardar: (cambios) => api.put('/portal/planificacion', { cambios }).then(completo),
  },

  bonos: {
    ciclos: () => api.get('/portal/bonos/ciclos').then(datos),
    listar: (params) => api.get('/portal/bonos', { params }).then(completo),
    proponer: (valores) => api.post('/portal/bonos', valores).then(datos),
  },

  ceses: {
    listar: (params) => api.get('/portal/ceses', { params }).then(completo),
    obtener: (id) => api.get(`/portal/ceses/${id}`).then(datos),
    solicitar: (formData) => api.post('/portal/ceses', formData).then(datos),
    evidencia: (id, evidenciaId) => api.get(`/portal/ceses/${id}/evidencias/${evidenciaId}`, { responseType: 'blob' }).then(completo),
  },

  personal: {
    listar: (params) => api.get('/portal/personal/solicitudes', { params }).then(completo),
  },
};
