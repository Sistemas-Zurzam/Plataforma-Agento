// Etiquetas de estado de las solicitudes del portal. La clave es el estado
// o etapa real que devuelve el backend; nunca se infiere en el frontend.
export const ESTADOS_PORTAL = {
  pendiente: ['Pendiente', 'gold'],
  aprobado_gerencia: ['Aprobada por gerencia', 'blue'],
  aprobado: ['Validada por RR. HH.', 'green'],
  rechazado: ['Rechazada', 'red'],
  propuesto: ['Propuesta', 'gold'],
  validado: ['Validada (no implica pago)', 'green'],
  enviada: ['Enviada', 'blue'],
  aprobada_pendiente_ejecucion: ['Aprobada · pendiente de ejecución', 'cyan'],
  ejecutada: ['Ejecutada', 'green'],
  rechazada: ['Rechazada', 'red'],
  borrador: ['Borrador', 'default'],
  devuelta: ['Devuelta para corrección', 'orange'],
  aceptada: ['Aceptada · en selección', 'green'],
  cancelada: ['Cancelada', 'default'],
};

export const RESPONSABLES_PORTAL = {
  rrhh: 'Siguiente paso: RR. HH.',
  gerencia: 'Siguiente paso: tú (gerencia)',
  solicitante: 'Siguiente paso: corregir y reenviar',
};

export const formatoMinutos = (minutos) => {
  if (minutos === null || minutos === undefined) return '—';
  const total = Number(minutos);
  return `${Math.floor(total / 60)}h ${String(total % 60).padStart(2, '0')}m`;
};

export const formatoFecha = (valor) => (valor ? valor.slice(0, 10).split('-').reverse().join('/') : '—');

export const formatoFechaHora = (valor) => (valor ? `${formatoFecha(valor)} ${valor.slice(11, 16)}` : '—');
