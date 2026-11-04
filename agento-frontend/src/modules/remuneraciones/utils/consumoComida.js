export const ESTADO_IMPORTACION = {
  validada: { color: 'blue', label: 'Validada' },
  con_errores: { color: 'red', label: 'Con errores' },
  aplicada: { color: 'green', label: 'Aplicada' },
  anulada: { color: 'default', label: 'Anulada' },
};

export const ESTADO_FILA = {
  valido: { color: 'green', label: 'Válido' },
  error: { color: 'red', label: 'Error' },
  posible_duplicado: { color: 'gold', label: 'Posible duplicado' },
};

/** Los montos llegan del backend como texto decimal ("12.50"): no se convierten a float. */
export const soles = (valor) => `S/ ${valor ?? '0.00'}`;

export const mensajeError = (err, porDefecto) => {
  const errores = err.response?.data?.errors;
  if (errores) return Object.values(errores).flat()[0];
  return err.response?.data?.message ?? porDefecto;
};
