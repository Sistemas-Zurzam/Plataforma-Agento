import { useCallback, useState } from 'react';
import api from '../../../services/api';

const BONIFICACIONES_OFICIALES = [
  ['0301', 'Bonificación por 25 y 30 años de servicios'],
  ['0302', 'Bonificación por cierre de pliego'],
  ['0303', 'Bonificación por producción, altura, turno, etc.'],
  ['0304', 'Bonificación por riesgo de caja'],
  ['0305', 'Bonificación por tiempo de servicios'],
  ['0306', 'Bonificaciones regulares'],
  ['0307', 'Bonificaciones CAFAE'],
  ['0308', 'Compensación por trabajos en días de descanso y feriados'],
  ['0309', 'Bonificación por turno nocturno 20% jornal básico'],
  ['0310', 'Bonificación contacto directo con agua 20% jornal básico'],
  ['0311', 'Bonificación unificada de construcción'],
  ['0312', 'Bonificación extraordinaria temporal - Ley 29351'],
  ['0313', 'Bonificación extraordinaria temporal proporcional - Ley 29351'],
  ['0314', 'Bonificación especial por trabajador agrario Ley 31110 - BETA'],
];

export function useConceptoDefinicionesPlame() {
  const [definiciones, setDefiniciones] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchDefiniciones = useCallback(async (conceptoId, conceptoCodigo) => {
    // Evita mostrar las clasificaciones del concepto anterior mientras
    // llega la nueva respuesta.
    setDefiniciones([]);
    setLoading(true);
    try {
      const endpoint = `/conceptos-remuneracion/${conceptoId}/definiciones-plame`;
      let { data } = await api.get(endpoint);

      // Respaldo idempotente para instalaciones donde la migración de datos
      // no se ejecutó: usa la API normal (con sus permisos y validaciones) y
      // vuelve a leer el catálogo. allSettled tolera que otro proceso haya
      // creado alguna fila entre ambas consultas.
      if (conceptoCodigo === 'BONIFICACION' && data.data.length === 0) {
        await Promise.allSettled(BONIFICACIONES_OFICIALES.map(([codigo_plame, nombre]) => api.post(endpoint, {
          nombre,
          codigo_plame,
          descripcion_sunat: 'Clasificación oficial precargada (SUNAT, Tabla 22).',
        })));
        ({ data } = await api.get(endpoint));
      }

      setDefiniciones(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const crearDefinicion = useCallback(async (conceptoId, valores) => {
    const { data } = await api.post(`/conceptos-remuneracion/${conceptoId}/definiciones-plame`, valores);
    setDefiniciones((actuales) => [...actuales, data.data]);
    return data.data;
  }, []);

  const actualizarDefinicion = useCallback(async (definicionId, valores) => {
    const { data } = await api.put(`/concepto-definiciones-plame/${definicionId}`, valores);
    setDefiniciones((actuales) => actuales.map((d) => (d.id === definicionId ? data.data : d)));
    return data.data;
  }, []);

  return { definiciones, loading, fetchDefiniciones, crearDefinicion, actualizarDefinicion };
}
