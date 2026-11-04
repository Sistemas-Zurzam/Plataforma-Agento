import { useCallback } from 'react';
import { useConsulta } from '../../reclutamiento/hooks/useConsulta';
import { propuestasConceptoApi } from '../services/propuestasConceptoApi';

export function usePropuestasConcepto({ estado, page }) {
  const cargar = useCallback(() => propuestasConceptoApi.listar({ estado, page, per_page: 20 }), [estado, page]);
  return { ...useConsulta(cargar), resolver: propuestasConceptoApi.resolver };
}

export function useCatalogoConceptos(habilitado) {
  const cargar = useCallback(() => propuestasConceptoApi.catalogoConceptos(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}
