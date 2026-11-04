import { useCallback } from 'react';
import { useConsulta } from '../../reclutamiento/hooks/useConsulta';
import { solicitudesCeseApi } from '../services/solicitudesCeseApi';

export function useSolicitudesCese({ etapa, page }) {
  const cargar = useCallback(() => solicitudesCeseApi.listar({ etapa, page, per_page: 20 }), [etapa, page]);
  return { ...useConsulta(cargar), acciones: solicitudesCeseApi };
}
