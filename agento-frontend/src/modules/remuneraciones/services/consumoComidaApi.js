import api from '../../../services/api';

/**
 * Cliente de consumo de comida (descuentos importados desde Excel). Nunca
 * envía empresa_id ni totales: el backend los resuelve desde el ciclo
 * autorizado y recalcula todo en servidor.
 */
const datos = (respuesta) => respuesta.data.data;

const descargar = async (url, nombre) => {
  const respuesta = await api.get(url, { responseType: 'blob' });
  const enlace = document.createElement('a');
  const objeto = URL.createObjectURL(respuesta.data);
  enlace.href = objeto;
  enlace.download = nombre;
  document.body.appendChild(enlace);
  enlace.click();
  enlace.remove();
  URL.revokeObjectURL(objeto);
};

export const consumoComidaApi = {
  resumen: (cicloId) => api.get(`/ciclos-remunerativos/${cicloId}/consumos-comida`).then(datos),
  consumosColaborador: (cicloId, colaboradorId) => api.get(`/ciclos-remunerativos/${cicloId}/consumos-comida/colaboradores/${colaboradorId}`).then(datos),
  importar: (cicloId, archivo, { sistemaOrigen, reemplazaImportacionId } = {}) => {
    const form = new FormData();
    form.append('archivo', archivo);
    if (sistemaOrigen) form.append('sistema_origen', sistemaOrigen);
    if (reemplazaImportacionId) form.append('reemplaza_importacion_id', String(reemplazaImportacionId));
    return api.post(`/ciclos-remunerativos/${cicloId}/consumos-comida/importaciones`, form).then(datos);
  },
  obtener: (importacionId) => api.get(`/consumos-comida/importaciones/${importacionId}`).then(datos),
  admitirDuplicados: (importacionId, detalleIds, motivo) => api.post(`/consumos-comida/importaciones/${importacionId}/admitir-duplicados`, { detalle_ids: detalleIds, motivo }).then(datos),
  aplicar: (importacionId, confirmarBoletasAprobadas = false) => api.post(`/consumos-comida/importaciones/${importacionId}/aplicar`, { confirmar_boletas_aprobadas: confirmarBoletasAprobadas }).then(datos),
  anular: (importacionId, motivo, confirmarBoletasAprobadas = false) => api.post(`/consumos-comida/importaciones/${importacionId}/anular`, { motivo, confirmar_boletas_aprobadas: confirmarBoletasAprobadas }).then(datos),
  descargarPlantilla: () => descargar('/consumos-comida/plantilla', 'plantilla-consumos-comida.xlsx'),
  descargarArchivo: (importacion) => descargar(`/consumos-comida/importaciones/${importacion.id}/archivo`, importacion.archivo_nombre_original),
  descargarErrores: (importacionId) => descargar(`/consumos-comida/importaciones/${importacionId}/errores`, `errores-consumos-comida-${importacionId}.xlsx`),
};
