import CarnetColaborador from './CarnetColaborador';
import CarnetColaboradorZazu from './CarnetColaboradorZazu';
import CarnetColaboradorLivex from './CarnetColaboradorLivex';
import CarnetColaboradorBoxPrime from './CarnetColaboradorBoxPrime';
import CarnetColaboradorTexajo from './CarnetColaboradorTexajo';
import CarnetColaboradorZurzam from './CarnetColaboradorZurzam';

/** Usado por VerCarnetModal para saber qué componente renderizar según
 * `empresa.plantilla_carnet`. */
export const PLANTILLA_GENERICA = CarnetColaborador;

export const PLANTILLAS_POR_EMPRESA = {
  zazu: CarnetColaboradorZazu,
  livex: CarnetColaboradorLivex,
  box_prime: CarnetColaboradorBoxPrime,
  texajo: CarnetColaboradorTexajo,
  zurzam: CarnetColaboradorZurzam,
};

/** Plantillas diseñadas al ancho nativo de Figma (540px) en vez de los
 * 260px de CarnetColaborador — se muestran reducidas en la vista previa
 * (ver ESCALA_VISTA_PREVIA); la impresión usa el ancho real renderizado. */
export const PLANTILLAS_ANCHAS = new Set(Object.values(PLANTILLAS_POR_EMPRESA));

export const ANCHO_NATIVO_PLANTILLA_ANCHA = 540;
export const ALTO_NATIVO_PLANTILLA_ANCHA = 860;
export const ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA = 300;
export const ESCALA_VISTA_PREVIA = ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA / ANCHO_NATIVO_PLANTILLA_ANCHA;

export function resolverPlantillaCarnet(plantillaCarnet) {
  return PLANTILLAS_POR_EMPRESA[plantillaCarnet] ?? PLANTILLA_GENERICA;
}
