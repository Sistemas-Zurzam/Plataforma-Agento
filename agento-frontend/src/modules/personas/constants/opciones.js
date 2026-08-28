export const TIPO_DOCUMENTO_OPTIONS = [
  { value: 'dni', label: 'DNI' },
  { value: 'ce', label: 'Carné de Extranjería' },
  { value: 'pasaporte', label: 'Pasaporte' },
];

// "ruc" solo se ofrece para locadores (Tabla 3 de SUNAT lo habilita
// exclusivamente para prestadores de servicios) — nunca para trabajador/
// practicante.
export const TIPO_DOCUMENTO_OPTIONS_LOCADOR = [
  ...TIPO_DOCUMENTO_OPTIONS,
  { value: 'ruc', label: 'RUC' },
];

export const TIPO_CONTRATO_OPTIONS = [
  { value: 'plazo_fijo', label: 'Plazo Fijo' },
  { value: 'indefinido', label: 'Indefinido' },
  { value: 'locacion_servicios', label: 'Locación de Servicios' },
  { value: 'practicas', label: 'Prácticas' },
];

export const TIPO_TRABAJADOR_OPTIONS = [
  { value: 'trabajador', label: 'Trabajador' },
  { value: 'practicante', label: 'Practicante' },
  { value: 'locador', label: 'Locador' },
];

// Solo aplica cuando tipo_trabajador = "trabajador" — SUNAT (Tabla 8)
// distingue Empleado (21) de Obrero (20); ver Catálogos SUNAT.
export const CATEGORIA_TRABAJADOR_OPTIONS = [
  { value: 'empleado', label: 'Empleado' },
  { value: 'obrero', label: 'Obrero' },
];

export const PERIODICIDAD_OPTIONS = [
  { value: 'mensual', label: 'Mensual' },
  { value: 'quincenal', label: 'Quincenal' },
  { value: 'semanal', label: 'Semanal' },
];

export const MODALIDAD_TRABAJO_OPTIONS = [
  { value: 'presencial', label: 'Presencial' },
  { value: 'remoto', label: 'Remoto' },
  { value: 'hibrido', label: 'Híbrido' },
];

export const TIPO_CUENTA_OPTIONS = [
  { value: 'ahorro', label: 'Ahorro' },
  { value: 'corriente', label: 'Corriente' },
];

export const MONEDA_OPTIONS = [
  { value: 'PEN', label: 'Soles (PEN)' },
  { value: 'USD', label: 'Dólares (USD)' },
];

export const BANCO_OPTIONS = [
  'BCP',
  'BBVA',
  'Interbank',
  'Scotiabank',
  'Banco de la Nación',
  'Banco Pichincha',
  'Banco GNB',
  'Otro',
].map((nombre) => ({ value: nombre, label: nombre }));
