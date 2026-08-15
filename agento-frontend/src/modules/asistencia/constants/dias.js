export const DIAS_SEMANA = [
  { value: 0, label: 'Lunes', abrev: 'Lu' },
  { value: 1, label: 'Martes', abrev: 'Ma' },
  { value: 2, label: 'Miércoles', abrev: 'Mi' },
  { value: 3, label: 'Jueves', abrev: 'Ju' },
  { value: 4, label: 'Viernes', abrev: 'Vi' },
  { value: 5, label: 'Sábado', abrev: 'Sá' },
  { value: 6, label: 'Domingo', abrev: 'Do' },
];

export const ESTADO_DIA_OPTIONS = [
  { value: 'pendiente', label: 'Pendiente' },
  { value: 'laborable', label: 'Laborable' },
  { value: 'descanso', label: 'Descanso' },
];

export function diaVacio(diaSemana) {
  return {
    dia_semana: diaSemana,
    estado: 'pendiente',
    hora_entrada: null,
    hora_salida: null,
    refrigerio_inicio: null,
    refrigerio_fin: null,
    jornada_nocturna: false,
    permitir_horas_extra: false,
  };
}
