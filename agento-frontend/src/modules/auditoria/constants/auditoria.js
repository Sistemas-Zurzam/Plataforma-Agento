export const TIPOS_EVENTO = {
  request: { texto: 'Operación', color: 'blue' },
  ui: { texto: 'Interfaz', color: 'default' },
  auth: { texto: 'Sesión', color: 'purple' },
};

export const COLORES_METODO = {
  GET: 'default',
  POST: 'green',
  PUT: 'orange',
  PATCH: 'orange',
  DELETE: 'red',
};

export function formatearFecha(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'medium' });
}
