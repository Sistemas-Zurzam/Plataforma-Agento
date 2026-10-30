import { Select } from 'antd';
import { useMemo } from 'react';
import PlanificacionRotativos from '../../asistencia/components/PlanificacionRotativos';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { portalApi } from '../services/portalApi';

/**
 * Misma pantalla de Asistencias › Planificación, con la fuente del portal:
 * solo el equipo autorizado, origen 'portal' asignado por el servidor y
 * guardado atómico con detección de cambios concurrentes (tipo_anterior).
 */
const FUENTE_PORTAL = {
  consultar: (params) => portalApi.planificacion.semana(params),
  guardarDia: ({ colaboradorId, fecha, tipo, tipoAnterior }) => portalApi.planificacion.guardar([
    { colaborador_id: colaboradorId, fecha, tipo, tipo_anterior: tipoAnterior },
  ]),
  guardarMasivo: ({ colaboradorIds, fechas, tipo, tipoAnterior }) => portalApi.planificacion.guardar(
    colaboradorIds.flatMap((colaboradorId) => fechas.map((fecha) => ({
      colaborador_id: colaboradorId, fecha, tipo, tipo_anterior: tipoAnterior(colaboradorId, fecha),
    }))),
  ).then((respuesta) => respuesta.data.aplicados),
};

export default function PlanificacionGerenciaPage({ areas }) {
  const [filtros, setFiltros] = useFiltrosUrl({ area_id: undefined, semana: undefined });
  const parametrosExtra = useMemo(() => ({ area_id: filtros.area_id || undefined }), [filtros.area_id]);

  return (
    <PlanificacionRotativos
      puedeEditar
      fuente={FUENTE_PORTAL}
      semanaInicial={filtros.semana}
      parametrosExtra={parametrosExtra}
      descripcion="Declara qué días le toca a cada colaborador rotativo de tu equipo: trabajo presencial (L), remoto (TR) o descanso (D). Los feriados (F) son de solo lectura. No se guarda ningún cambio de un lote si algún día está cerrado, ajustado por RR. HH. o fue modificado por otra persona: verás la lista de conflictos."
      filtrosExtra={(
        <Select
          allowClear
          placeholder="Todas mis áreas"
          className="w-56"
          aria-label="Filtrar por área"
          value={filtros.area_id ? Number(filtros.area_id) : undefined}
          options={areas.map((a) => ({ value: a.id, label: a.nombre }))}
          onChange={(valor) => setFiltros({ area_id: valor })}
        />
      )}
    />
  );
}
