import { colorForName, initialsForName } from '../../../utils/avatarColor';
import CarnetBarcode from './CarnetBarcode';
import logoBoxPrime from './carnetBoxPrimeAssets/logo-box-prime.svg';
import formaProfundidad from './carnetBoxPrimeAssets/forma-profundidad.svg';

const NARANJA = '#ffb547';

/** Rombo = cuadrado rotado 45°/135° — así se construyeron en el diseño de
 * Figma (no son imágenes, salvo "Depth Shape 2" que sí es un SVG aparte). */
function Rombo({ left, top, contenedor, tamano, rotacion, className, style }) {
  return (
    <div className="absolute flex items-center justify-center" style={{ left, top, width: contenedor, height: contenedor }}>
      <div style={{ transform: `rotate(${rotacion}deg)`, width: tamano, height: tamano, ...style }} className={`rounded-[10px] ${className}`} />
    </div>
  );
}

/**
 * Plantilla de carnet EXCLUSIVA para Box Prime (diseño Figma "Foto check"),
 * igual que CarnetColaboradorZazu/Livex — wordmark y eslogan de marca fijos
 * en el componente (no vienen de `colaborador.empresa`).
 *
 * Tamaño 540x860px, proporción 54:86 (tarjeta PVC física) — ver el mismo
 * comentario en CarnetColaboradorZazu sobre por qué no hace falta que
 * coincida con el ancho de CarnetColaborador (260px).
 */
export default function CarnetColaboradorBoxPrime({ colaborador, fotoUrl }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative overflow-hidden rounded-[31px] bg-white shadow-[0px_6px_18px_0px_rgba(0,0,0,0.35)]"
      style={{ width: 540, height: 860 }}
    >
      {/* Decoración de marca — rombos naranja/beige que asoman por los bordes */}
      <Rombo left={-116} top={178} contenedor={205.871} tamano={145.573} rotacion={-45} className="border-solid opacity-80" style={{ border: `1.12px solid ${NARANJA}` }} />
      <Rombo left={498} top={75} contenedor={139.064} tamano={98.333} rotacion={-45} className="border-solid opacity-80" style={{ border: `1.12px solid ${NARANJA}` }} />
      <Rombo left={480} top={785} contenedor={139.064} tamano={98.333} rotacion={-45} className="border-solid opacity-80" style={{ border: `1.12px solid ${NARANJA}` }} />
      <Rombo left={466} top={190} contenedor={327.974} tamano={231.913} rotacion={-45} className="" style={{ background: `linear-gradient(90deg, ${NARANJA}, #ec932f)` }} />
      <Rombo left={-103} top={785} contenedor={187.839} tamano={132.822} rotacion={-45} className="bg-[#e7e2d8] opacity-50" />
      <Rombo left={-238} top={155} contenedor={328.147} tamano={232.035} rotacion={-135} className="bg-[#e7e2d8] opacity-55" />
      <div className="absolute flex items-center justify-center" style={{ left: -145, top: 538, width: 209.538, height: 209.538 }}>
        <div className="rounded-[10px]" style={{ transform: 'rotate(135deg)', width: 147.245, height: 149.085, background: `linear-gradient(90deg, ${NARANJA}, #d97017)` }} />
      </div>
      <div className="absolute flex items-center justify-center" style={{ left: 480, top: 609, width: 182.52, height: 182.52 }}>
        <img src={formaProfundidad} alt="" style={{ transform: 'rotate(-45deg)', width: 129.061, height: 129.061 }} />
      </div>

      <img src={logoBoxPrime} alt="Box Prime" className="absolute left-1/2 h-[115px] w-[230px] -translate-x-1/2" style={{ top: 30 }} />

      <div className="absolute flex w-[344px] flex-col items-center gap-12" style={{ left: 98, top: 178 }}>
        <div className="relative h-[291px] w-[258px] overflow-hidden rounded-[16px] border-[2.8px]" style={{ borderColor: NARANJA }}>
          {fotoUrl ? (
            <img src={fotoUrl} alt={colaborador.nombre_completo} className="h-full w-full object-cover" />
          ) : (
            <div
              className="flex h-full w-full items-center justify-center text-4xl font-bold text-white"
              style={{ backgroundColor: colorForName(colaborador.nombre_completo) }}
            >
              {initialsForName(colaborador.nombre_completo)}
            </div>
          )}
        </div>

        <div className="flex w-full flex-col items-center gap-[60px]">
          <div className="flex w-full flex-col items-center gap-10">
            <div className="flex flex-col items-center gap-3 text-center text-[#171719]">
              <p className="text-[40px] leading-none font-bold">{nombreCarnet}</p>
              <p className="text-[20px]">
                <span className="font-bold">DNI:</span> {colaborador.numero_documento}
              </p>
              <p className="text-[20px]">
                <span className="font-bold">Cargo:</span> {colaborador.cargo ?? 'Colaborador'}
              </p>
            </div>

            <div className="flex items-center justify-center">
              <CarnetBarcode valor={colaborador.numero_documento} color="#1a1f1c" />
            </div>
          </div>

          <div className="flex items-center gap-3">
            <span className="h-[2px] w-[60px]" style={{ backgroundColor: NARANJA }} />
            <p className="text-[14px] font-bold tracking-[1.96px] whitespace-nowrap text-[#171719]">PERSONAS QUE SUMAN</p>
            <span className="h-px w-[60px]" style={{ backgroundColor: NARANJA }} />
          </div>
        </div>
      </div>
    </div>
  );
}
