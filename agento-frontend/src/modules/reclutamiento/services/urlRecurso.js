import api from '../../../services/api';

/**
 * El backend entrega las imágenes de pruebas como rutas firmadas relativas
 * ("/api/publico/pruebas/imagenes/5?expires=…&signature=…"). Se resuelven
 * contra el origen de la API: en desarrollo el backend corre en otro puerto.
 */
export function urlRecurso(ruta) {
  if (!ruta) return null;
  const base = api.defaults.baseURL ?? '/api';
  return new URL(ruta, base.startsWith('http') ? base : window.location.origin).href;
}
