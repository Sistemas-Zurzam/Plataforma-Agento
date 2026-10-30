import api from '../../../services/api';

/** Bandeja de RR. HH. de propuestas de bono/comisión del Portal de Empresa. */
export const propuestasConceptoApi = {
  listar: (params) => api.get('/propuestas-conceptos', { params }).then((r) => r.data),
  resolver: (id, valores) => api.patch(`/propuestas-conceptos/${id}`, valores).then((r) => r.data.data),
  catalogoConceptos: () => api.get('/conceptos-remuneracion').then((r) => r.data.data),
};
