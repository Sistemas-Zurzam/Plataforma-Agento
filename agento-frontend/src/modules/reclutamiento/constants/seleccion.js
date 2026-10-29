export const ETAPAS = [
  { value: 'postulacion', label: 'Postulación' },
  { value: 'filtro_cv', label: 'Filtro CV' },
  { value: 'psicometrica', label: 'Psicométrica' },
  { value: 'entrevista_virtual', label: 'Entrevista virtual' },
  { value: 'entrevista_presencial', label: 'Entrevista presencial' },
  { value: 'seleccion', label: 'Selección' },
];

export const ETIQUETA_ETAPA = Object.fromEntries(ETAPAS.map((e) => [e.value, e.label]));

export const ESTADOS_SOLICITUD = {
  borrador: { label: 'Borrador', color: 'default' },
  enviada: { label: 'Enviada', color: 'blue' },
  devuelta: { label: 'Devuelta', color: 'orange' },
  aceptada: { label: 'Aceptada', color: 'green' },
  rechazada: { label: 'Rechazada', color: 'red' },
  cancelada: { label: 'Cancelada', color: 'default' },
};

export const ESTADOS_PROCESO = {
  borrador: { label: 'Borrador', color: 'default' },
  abierto: { label: 'Abierto', color: 'green' },
  en_pausa: { label: 'En pausa', color: 'orange' },
  cubierto: { label: 'Cubierto', color: 'blue' },
  cerrado: { label: 'Cerrado', color: 'default' },
  cancelado: { label: 'Cancelado', color: 'red' },
};

export const ESTADOS_POSTULACION = {
  activa: { label: 'Activa', color: 'blue' },
  seleccionada: { label: 'Seleccionada', color: 'green' },
  contratada: { label: 'Contratada', color: 'green' },
  descartada: { label: 'Descartada', color: 'red' },
  retirada: { label: 'Retirada', color: 'default' },
  finalizada_por_cancelacion: { label: 'Finalizada (cancelación)', color: 'default' },
};

export const ESTADOS_PLAZA = {
  disponible: { label: 'Disponible', color: 'orange' },
  cubierta: { label: 'Cubierta', color: 'green' },
  cancelada: { label: 'Cancelada', color: 'default' },
};

export const MOTIVOS_SOLICITUD = [
  { value: 'reemplazo', label: 'Reemplazo' },
  { value: 'nuevo_puesto', label: 'Nuevo puesto' },
  { value: 'temporal', label: 'Temporal' },
];

export const TIPOS_CONTRATO = [
  { value: 'plazo_fijo', label: 'Plazo fijo' },
  { value: 'indefinido', label: 'Indefinido' },
  { value: 'locacion_servicios', label: 'Locación de servicios' },
  { value: 'practicas', label: 'Prácticas' },
];

export const MODALIDADES = [
  { value: 'presencial', label: 'Presencial' },
  { value: 'remoto', label: 'Remoto' },
  { value: 'hibrido', label: 'Híbrido' },
];

export const CAUSAS_DESCARTE = [
  { value: 'no_cumple_requisitos', label: 'No cumple requisitos' },
  { value: 'bajo_puntaje', label: 'Bajo puntaje' },
  { value: 'resultado_evaluacion', label: 'Resultado de evaluación' },
  { value: 'resultado_entrevista', label: 'Resultado de entrevista' },
  { value: 'expectativa_salarial', label: 'Expectativa salarial' },
  { value: 'no_se_presento', label: 'No se presentó' },
  { value: 'otro_candidato_seleccionado', label: 'Otro candidato seleccionado' },
  { value: 'otro', label: 'Otro' },
];

export const FUENTES = {
  portal_publico: 'Portal público',
  portal_interno: 'Publicación interna',
  invitacion: 'Invitación',
  banco_talentos: 'Banco de talentos',
  registro_manual: 'Registro manual',
};

export const TIPOS_ENTREVISTA = [
  { value: 'virtual', label: 'Virtual' },
  { value: 'presencial', label: 'Presencial' },
  { value: 'gerencia', label: 'Gerencia' },
];

export const RECOMENDACIONES = [
  { value: 'avanzar', label: 'Avanzar' },
  { value: 'en_duda', label: 'En duda' },
  { value: 'no_avanzar', label: 'No avanzar' },
];

export const RESULTADOS_ATS = {
  apto: { label: 'Apto', color: 'green' },
  bajo_umbral: { label: 'Bajo umbral', color: 'orange' },
  no_apto: { label: 'Excluyente incumplido', color: 'red' },
  requiere_revision: { label: 'Requiere revisión', color: 'orange' },
  error: { label: 'Error', color: 'red' },
};

// Texto de consentimiento del portal. Al cambiarlo, incrementar
// RECLUTAMIENTO_CONSENTIMIENTO_VERSION en el backend: cada aceptación guarda
// esa versión y el hash de este texto.
export const TEXTO_CONSENTIMIENTO = 'Autorizo el tratamiento de mis datos personales y de mi CV para este proceso de selección y para que la empresa me considere en futuros procesos durante el plazo de conservación informado. Puedo solicitar el acceso, rectificación o eliminación de mis datos.';

export const etiquetaDe =(lista, valor) => lista.find((item) => item.value === valor)?.label ?? valor;

/**
 * Mensaje legible de un error de Axios: primero el error de validación del
 * campo, luego el mensaje general del backend.
 */
export function mensajeDeError(error, porDefecto = 'No se pudo completar la acción.') {
  const datos = error?.response?.data;
  const primerError = datos?.errors ? Object.values(datos.errors).flat()[0] : null;
  return primerError ?? datos?.message ?? porDefecto;
}

export function tiene(user, ...permisos) {
  return user?.role === 'administrador' || permisos.some((p) => user?.permisos?.includes(p));
}
