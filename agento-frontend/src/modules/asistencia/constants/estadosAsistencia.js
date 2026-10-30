// Leyenda estándar de Asistencias: [código, etiqueta, color de Tag]. Fuente
// única para Gestión de Asistencias y el Portal de Empresa.
export const ESTADOS_ASISTENCIA = {
  presente: ['P', 'Presente', 'green'], tardanza: ['T', 'Tardanza', 'gold'], falta: ['F', 'Falta', 'red'],
  marcacion_incompleta: ['MI', 'Marcación incompleta', 'orange'],
  horario_desplazado: ['HD', 'Horario desplazado', 'purple'],
  horas_incompletas: ['HI', 'Horas incompletas', 'volcano'],
  home_office: ['TR', 'Trabajo remoto', 'blue'], descanso: ['D', 'Descanso', 'default'],
  feriado: ['D', 'Feriado', 'default'], no_programado: ['—', 'Sin procesar', 'default'],
  permiso: ['PE', 'Permiso', 'cyan'], falta_justificada: ['FJ', 'Falta justificada', 'gold'],
  dia_sin_clasificar: ['?', 'Rotativo sin planificar — pendiente de clasificación', 'magenta'],
};

// "Tardanza" no es un estado guardado: es un presente con minutos de tardanza.
export const claveEstadoAsistencia = (resultado) => (
  resultado?.estado === 'presente' && resultado?.minutos_tardanza > 0 ? 'tardanza' : resultado?.estado
);
