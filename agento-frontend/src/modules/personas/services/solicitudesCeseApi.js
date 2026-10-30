import api from '../../../services/api';

/** Bandeja de RR. HH. de solicitudes de cese enviadas desde el Portal de Empresa. */
export const solicitudesCeseApi = {
  listar: (params) => api.get('/solicitudes-cese', { params }).then((r) => r.data),
  resolver: (id, valores) => api.patch(`/solicitudes-cese/${id}/decision`, valores).then((r) => r.data.data),
  ejecutar: (id, valores) => api.post(`/solicitudes-cese/${id}/ejecutar`, valores).then((r) => r.data.data),
  evidencia: (id, evidenciaId) => api.get(`/solicitudes-cese/${id}/evidencias/${evidenciaId}`, { responseType: 'blob' }).then((r) => r.data),
};
