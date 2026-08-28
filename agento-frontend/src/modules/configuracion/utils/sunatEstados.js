/**
 * Los 4 estados de cualquier fila de Catálogos SUNAT — mismo significado en
 * las 3 fuentes (sunat_mapeos, tipos_ausencia, conceptos_remuneracion), ver
 * SunatCatalogoService::calcularEstado() en el backend (fuente de verdad,
 * esto solo espeja las mismas etiquetas/colores para la UI).
 */
export const ESTADO_LABELS = {
  configurado: 'Configurado',
  requiere_configuracion: 'Requiere configuración',
  bloqueado_por_modelo: 'Bloqueado por modelo',
  no_aplica: 'No aplica',
};

// Colores del Design System (Ant Design) ya usados en el resto de Agento —
// verde=éxito, naranja=advertencia, rojo=bloqueante, gris=neutro.
export const ESTADO_COLORS = {
  configurado: 'green',
  requiere_configuracion: 'orange',
  bloqueado_por_modelo: 'red',
  no_aplica: 'default',
};

export const ESTADO_FILTRO_OPTIONS = [
  { value: 'todos', label: 'Todos' },
  { value: 'configurado', label: 'Configurados' },
  { value: 'requiere_configuracion', label: 'Requieren configuración' },
  { value: 'bloqueado_por_modelo', label: 'Bloqueados por modelo' },
  { value: 'no_aplica', label: 'No aplica' },
];
