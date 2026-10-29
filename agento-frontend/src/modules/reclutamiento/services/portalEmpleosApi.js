import axios from 'axios';

/**
 * Cliente del portal público (/api/publico). Instancia aparte a propósito:
 * nunca adjunta el JWT del personal interno ni dispara el cierre de sesión
 * administrativo ante un 401. El candidato se identifica con un token opaco
 * emitido tras verificar su correo, guardado solo en sessionStorage.
 */
const CLAVE_TOKEN = 'agento_candidato_token';

const cliente = axios.create({
  baseURL: `${import.meta.env.VITE_API_URL ?? (import.meta.env.DEV ? 'http://127.0.0.1:8000/api' : '/api')}/publico`,
});

const leerToken = () => {
  try {
    return sessionStorage.getItem(CLAVE_TOKEN);
  } catch {
    return null;
  }
};

cliente.interceptors.request.use((config) => {
  const token = leerToken();
  if (token) {
    config.headers['X-Candidato-Token'] = token;
  }
  return config;
});

const datos = (respuesta) => respuesta.data.data;

export const sesionCandidato = {
  obtener: leerToken,
  guardar: (token) => {
    try {
      sessionStorage.setItem(CLAVE_TOKEN, token);
    } catch {
      // Sin almacenamiento disponible la sesión dura solo esta pestaña en memoria.
    }
  },
  limpiar: () => {
    try {
      sessionStorage.removeItem(CLAVE_TOKEN);
    } catch {
      // Ignorado: no hay nada que limpiar.
    }
  },
};

export const portalEmpleosApi = {
  listar: (params) => cliente.get('/empleos', { params }).then((r) => r.data),
  obtener: (codigo, invitacion) => cliente.get(`/empleos/${codigo}`, { params: { invitacion } }).then(datos),
  postular: (codigo, formulario) => cliente.post(`/empleos/${codigo}/postulaciones`, formulario).then(datos),

  solicitarCodigo: (email) => cliente.post('/candidatos/codigo', { email }).then((r) => r.data),
  verificarCodigo: (email, codigo) => cliente.post('/candidatos/verificar', { email, codigo }).then(datos),
  cerrarSesion: () => cliente.post('/candidatos/sesion/cerrar').then((r) => r.data),
  perfil: () => cliente.get('/candidatos/perfil').then(datos),
  misPostulaciones: () => cliente.get('/candidatos/postulaciones').then(datos),
  retirar: (id, motivo) => cliente.post(`/candidatos/postulaciones/${id}/retiro`, { motivo }).then(datos),
  accesoPrueba: (asignacionId) => cliente.post(`/candidatos/pruebas/${asignacionId}/acceso`).then(datos),

  prueba: (token) => cliente.get(`/pruebas/${token}`).then(datos),
  iniciarPrueba: (token) => cliente.post(`/pruebas/${token}/iniciar`).then(datos),
  responder: (token, preguntaId, opcionId) => cliente.post(`/pruebas/${token}/respuestas`, { pregunta_id: preguntaId, opcion_id: opcionId }).then(datos),
  finalizarPrueba: (token) => cliente.post(`/pruebas/${token}/finalizar`).then(datos),
};
