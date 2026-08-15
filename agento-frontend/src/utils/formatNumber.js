const numberFormatter = new Intl.NumberFormat('es-PE', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

export function formatParametroValor(valor, unidad) {
  if (valor === null || valor === undefined) return 'Sin definir';

  const numero = numberFormatter.format(Number(valor));

  if (unidad === 'S/') return `S/ ${numero}`;
  if (unidad === '%') return `${numero}%`;
  return `${numero} ${unidad}`;
}
