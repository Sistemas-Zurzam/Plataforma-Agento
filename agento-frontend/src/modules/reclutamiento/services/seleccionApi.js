import api from '../../../services/api';

/**
 * Cliente de /api/seleccion. Nunca envía empresa_id: la empresa la resuelve
 * el backend desde el JWT.
 */
const datos = (respuesta) => respuesta.data.data;
const completo = (respuesta) => respuesta.data;

export const seleccionApi = {
  dashboard: (params) => api.get('/seleccion/dashboard', { params }).then(datos),
  tareas: (params) => api.get('/seleccion/tareas', { params }).then(completo),
  completarTarea: (id) => api.patch(`/seleccion/tareas/${id}/completar`).then(datos),
  usuarios: () => api.get('/seleccion/usuarios').then(datos),

  solicitudes: {
    listar: (params) => api.get('/seleccion/solicitudes', { params }).then(completo),
    referencias: () => api.get('/seleccion/solicitudes/referencias').then(datos),
    obtener: (id) => api.get(`/seleccion/solicitudes/${id}`).then(datos),
    crear: (valores) => api.post('/seleccion/solicitudes', valores).then(datos),
    actualizar: (id, valores) => api.put(`/seleccion/solicitudes/${id}`, valores).then(datos),
    enviar: (id) => api.post(`/seleccion/solicitudes/${id}/enviar`).then(datos),
    decidir: (id, valores) => api.post(`/seleccion/solicitudes/${id}/decision`, valores).then(datos),
    impactoCancelacion: (id) => api.get(`/seleccion/solicitudes/${id}/impacto-cancelacion`).then(datos),
    cancelar: (id, motivo) => api.post(`/seleccion/solicitudes/${id}/cancelar`, { motivo }).then(completo),
  },

  procesos: {
    listar: (params) => api.get('/seleccion/procesos', { params }).then(completo),
    obtener: (id) => api.get(`/seleccion/procesos/${id}`).then(completo),
    actualizar: (id, valores) => api.put(`/seleccion/procesos/${id}`, valores).then(datos),
    guardarPerfil: (id, valores) => api.put(`/seleccion/procesos/${id}/perfil`, valores).then(datos),
    publicar: (id, valores) => api.post(`/seleccion/procesos/${id}/publicar`, valores).then(datos),
    retirarPublicacion: (id, motivo) => api.post(`/seleccion/procesos/${id}/retirar-publicacion`, { motivo }).then(datos),
    cambiarEstado: (id, valores) => api.post(`/seleccion/procesos/${id}/estado`, valores).then(datos),
    actividad: (id) => api.get(`/seleccion/procesos/${id}/actividad`).then(datos),
    tablero: (id, params) => api.get(`/seleccion/procesos/${id}/tablero`, { params }).then(datos),
    coincidencias: (id) => api.get(`/seleccion/procesos/${id}/coincidencias`).then(datos),
    invitar: (id, valores) => api.post(`/seleccion/procesos/${id}/invitaciones`, valores).then(datos),
  },

  postulaciones: {
    listar: (params) => api.get('/seleccion/postulaciones', { params }).then(completo),
    obtener: (id) => api.get(`/seleccion/postulaciones/${id}`).then(datos),
    descargarCv: (id) => api.get(`/seleccion/postulaciones/${id}/cv`, { responseType: 'blob' }).then((r) => r.data),
    mover: (id, valores) => api.post(`/seleccion/postulaciones/${id}/mover`, valores).then(datos),
    seleccionar: (id, motivo) => api.post(`/seleccion/postulaciones/${id}/seleccionar`, { motivo }).then(datos),
    descartar: (id, valores) => api.post(`/seleccion/postulaciones/${id}/descartar`, valores).then(datos),
    reconsiderar: (id, motivo) => api.post(`/seleccion/postulaciones/${id}/reconsiderar`, { motivo }).then(datos),
    registrarRetiro: (id, motivo) => api.post(`/seleccion/postulaciones/${id}/retiro`, { motivo }).then(datos),
    noAplica: (id, valores) => api.post(`/seleccion/postulaciones/${id}/no-aplica`, valores).then(datos),
    evaluarAts: (id, valores) => api.post(`/seleccion/postulaciones/${id}/evaluacion-ats`, valores).then(datos),
    accionMasiva: (valores) => api.post('/seleccion/postulaciones/acciones-masivas', valores).then(datos),
    programarEntrevista: (id, valores) => api.post(`/seleccion/postulaciones/${id}/entrevistas`, valores).then(datos),
    asignarPrueba: (id, pruebaId) => api.post(`/seleccion/postulaciones/${id}/pruebas/${pruebaId}`).then(datos),
    contratar: (id, valores) => api.post(`/seleccion/postulaciones/${id}/contratar`, valores).then(datos),
  },

  entrevistas: {
    mias: () => api.get('/seleccion/entrevistas/mias').then(datos),
    cancelar: (id, motivo) => api.post(`/seleccion/entrevistas/${id}/cancelar`, { motivo }).then(datos),
    evaluar: (id, valores) => api.post(`/seleccion/entrevistas/${id}/evaluaciones`, valores).then(datos),
  },

  pruebas: {
    listar: (params) => api.get('/seleccion/pruebas', { params }).then(completo),
    obtener: (id) => api.get(`/seleccion/pruebas/${id}`).then(datos),
    crear: (valores) => api.post('/seleccion/pruebas', valores).then(datos),
    actualizar: (id, valores) => api.put(`/seleccion/pruebas/${id}`, valores).then(datos),
    publicar: (id) => api.post(`/seleccion/pruebas/${id}/publicar`).then(datos),
    validar: (id, fuente) => api.post(`/seleccion/pruebas/${id}/validar`, { motivo: fuente }).then(datos),
    nuevaVersion: (id) => api.post(`/seleccion/pruebas/${id}/nueva-version`).then(datos),
    archivar: (id) => api.post(`/seleccion/pruebas/${id}/archivar`).then(datos),
  },

  contratacion: {
    opciones: () => api.get('/seleccion/contratacion/opciones').then(datos),
  },

  banco: {
    listar: (params) => api.get('/seleccion/banco-talentos', { params }).then(completo),
    obtener: (id) => api.get(`/seleccion/banco-talentos/${id}`).then(datos),
    etiquetar: (id, nombre) => api.post(`/seleccion/banco-talentos/${id}/etiquetas`, { nombre }).then(datos),
    quitarEtiqueta: (id, etiquetaId) => api.delete(`/seleccion/banco-talentos/${id}/etiquetas/${etiquetaId}`).then(datos),
  },
};
