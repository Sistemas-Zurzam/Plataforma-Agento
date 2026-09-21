/** Valores crudos reales guardados en `asistencia_marcaciones.origen` — es
 * un string libre en backend (sin enum), así que este mapa es solo de
 * presentación: un origen no listado aquí simplemente muestra su valor
 * crudo (nunca se rompe ni queda en blanco por un origen nuevo/desconocido). */
const ETIQUETAS_ORIGEN_MARCACION = {
  transaction: 'Huellero',
  'Attendance Device': 'Huellero',
  manual_rrhh: 'Manual',
  carnet_codigo_barras: 'Carnet / Código de barras',
  manual_vigilancia: 'Manual (kiosco, sin carnet)',
};

export function etiquetaOrigenMarcacion(origen) {
  return ETIQUETAS_ORIGEN_MARCACION[origen] ?? origen;
}
