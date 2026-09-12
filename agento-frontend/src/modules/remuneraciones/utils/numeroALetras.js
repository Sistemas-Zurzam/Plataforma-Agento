const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
const DIEZ_A_DIECINUEVE = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
const DECENAS = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

function convertirDecenas(numero) {
  if (numero < 10) return UNIDADES[numero];
  if (numero < 20) return DIEZ_A_DIECINUEVE[numero - 10];
  const decena = Math.floor(numero / 10);
  const unidad = numero % 10;
  if (decena === 2) return unidad === 0 ? 'VEINTE' : `VEINTI${UNIDADES[unidad]}`;
  return unidad === 0 ? DECENAS[decena] : `${DECENAS[decena]} Y ${UNIDADES[unidad]}`;
}

function convertirCentenas(numero) {
  if (numero === 100) return 'CIEN';
  const centena = Math.floor(numero / 100);
  const resto = numero % 100;
  return [centena > 0 ? CENTENAS[centena] : '', resto > 0 ? convertirDecenas(resto) : ''].filter(Boolean).join(' ');
}

// "UNO"/"VEINTIUNO" es la forma correcta en aislamiento, pero se apocopa a
// "UN"/"VEINTIUN" cuando antecede directamente a un sustantivo (MIL/MILLONES).
function apocopar(letras) {
  return letras.endsWith('UNO') ? `${letras.slice(0, -3)}UN` : letras;
}

function convertirMiles(numero) {
  const miles = Math.floor(numero / 1000);
  const resto = numero % 1000;
  const letras = miles > 0 ? (miles === 1 ? 'MIL' : `${apocopar(convertirCentenas(miles))} MIL`) : '';
  return [letras, resto > 0 ? convertirCentenas(resto) : ''].filter(Boolean).join(' ');
}

function convertirMillones(numero) {
  const millones = Math.floor(numero / 1000000);
  const resto = numero % 1000000;
  const letras = millones > 0 ? (millones === 1 ? 'UN MILLON' : `${apocopar(convertirMiles(millones))} MILLONES`) : '';
  return [letras, resto > 0 ? convertirMiles(resto) : ''].filter(Boolean).join(' ');
}

const NOMBRES_MONEDA = { PEN: 'SOLES', USD: 'DOLARES' };

/**
 * Convierte un monto a su representación legal en letras, formato
 * "ENTERO Y CENTAVOS/100 MONEDA" usado en boletas de pago peruanas.
 */
export function numeroALetras(monto, monedaCodigo = 'PEN') {
  const valor = Math.abs(Number(monto ?? 0));
  const entero = Math.floor(valor);
  const centavos = Math.round((valor - entero) * 100);
  const letrasEntero = entero === 0 ? 'CERO' : convertirMillones(entero);
  const nombreMoneda = NOMBRES_MONEDA[monedaCodigo] ?? NOMBRES_MONEDA.PEN;

  return `${letrasEntero} Y ${String(centavos).padStart(2, '0')}/100 ${nombreMoneda}`;
}
