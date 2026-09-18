import { colorForName, initialsForName } from '../../../utils/avatarColor';
import CarnetBarcode from './CarnetBarcode';
import logoTexajo from './carnetTexajoAssets/logo-texajo.svg';

const AZUL_OSCURO = '#20384a';

/** "Hilo" diagonal — las 3 franjas de tela en la esquina superior izquierda. */
function HiloDiagonal({ left, top, contenedor, rotacion, color, ancho }) {
  return (
    <div className="absolute flex items-center justify-center" style={{ left, top, width: contenedor, height: contenedor }}>
      <div className="h-[10px] rounded-[1.5px]" style={{ transform: `rotate(${rotacion}deg)`, width: ancho, backgroundColor: color }} />
    </div>
  );
}

/** Cúmulo de 4 franjas de tela — se repite dos veces (una normal, una en
 * espejo) para decorar ambas esquinas inferiores, igual que en el diseño. */
function AcentoTextil({ left, top, espejo }) {
  return (
    <div className="absolute" style={{ left, top, width: 300, height: 160, transform: espejo ? 'scaleX(-1)' : undefined }}>
      <div className="absolute flex items-center justify-center" style={{ left: -6.8, top: 60, width: 406.242, height: 150.71 }}>
        <div className="h-[22px] w-[420px]" style={{ transform: 'rotate(18deg)', backgroundColor: '#e8eff4' }} />
      </div>
      <div className="absolute flex items-center justify-center" style={{ left: 15.06, top: 95, width: 366.346, height: 132.643 }}>
        <div className="h-4 w-[380px]" style={{ transform: 'rotate(18deg)', backgroundColor: '#cad9e5' }} />
      </div>
      <div className="absolute flex items-center justify-center" style={{ left: 56.91, top: 125, width: 288.407, height: 102.216 }}>
        <div className="h-[10px] w-[300px]" style={{ transform: 'rotate(18deg)', backgroundColor: 'rgba(169,192,210,0.8)' }} />
      </div>
      <div className="absolute flex items-center justify-center" style={{ left: 40, top: -48.64, width: 41.599, height: 124.101 }}>
        <div className="h-[1.5px] w-[130px]" style={{ transform: 'rotate(-72deg)', backgroundColor: 'rgba(169,192,210,0.6)' }} />
      </div>
    </div>
  );
}

/**
 * Plantilla de carnet EXCLUSIVA para Texajo (diseño Figma "Foto check"),
 * igual que las demás — wordmark y eslogan de marca fijos en el componente
 * (no vienen de `colaborador.empresa`).
 *
 * Tamaño 540x860px, proporción 54:86 (tarjeta PVC física) — ver el mismo
 * comentario en CarnetColaboradorZazu sobre por qué no hace falta que
 * coincida con el ancho de CarnetColaborador (260px).
 */
export default function CarnetColaboradorTexajo({ colaborador, fotoUrl, credencialToken, credencialActiva }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative overflow-hidden rounded-[28px] bg-white"
      style={{ width: 540, height: 860 }}
    >
      <HiloDiagonal left={-58} top={-35} contenedor={173.77} rotacion={-43.59} color="#a9c0d2" ancho={230.394} />
      <HiloDiagonal left={-17.25} top={-3.05} contenedor={129.503} rotacion={-43.86} color="#cad9e5" ancho={170} />
      <HiloDiagonal left={-17} top={0} contenedor={161.459} rotacion={-43.83} color="#e8eff4" ancho={214.229} />
      <AcentoTextil left={-167} top={680} />
      <AcentoTextil left={352} top={692} espejo />

      <div className="absolute inset-0 flex flex-col items-center justify-center gap-8">
        <img src={logoTexajo} alt="Texajo" className="h-[52px] w-[260px]" />

        <div className="flex w-full flex-col items-center gap-10">
          <div className="flex flex-col items-center gap-8">
            <div className="relative h-[295px] w-[270px] overflow-hidden rounded-[22px] border-[3px] border-[#a9c0d2]">
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

            <div className="flex flex-col items-center gap-3 text-center" style={{ color: AZUL_OSCURO }}>
              <p className="text-[40px] leading-none font-extrabold">{nombreCarnet}</p>
              <p className="text-[20px] font-semibold tracking-[1.2px]">
                Cargo: <span className="font-normal">{colaborador.cargo ?? 'Colaborador'}</span>
              </p>
              <p className="text-[20px] font-semibold">
                DNI: <span className="font-normal">{colaborador.numero_documento}</span>
              </p>
            </div>
          </div>

          <div className="flex items-center justify-center">
            {credencialToken ? (
              <CarnetBarcode valor={credencialToken} color={AZUL_OSCURO} />
            ) : (
              <div
                className={`flex h-27.5 w-98 items-center justify-center rounded-md border ${
                  credencialActiva ? 'border-gray-200' : 'border-dashed border-gray-300'
                }`}
              >
                <span className={`text-[13px] font-semibold tracking-wide uppercase ${credencialActiva ? 'text-gray-500' : 'text-gray-400'}`}>
                  {credencialActiva ? 'Carnet habilitado' : 'Carnet sin habilitar'}
                </span>
              </div>
            )}
          </div>

          <div className="flex items-center gap-4">
            <span className="h-px w-[34px]" style={{ backgroundColor: 'rgba(32,56,74,0.5)' }} />
            <p className="text-[13px] font-medium tracking-[2px] whitespace-nowrap" style={{ color: AZUL_OSCURO }}>
              TEJEMOS UN MEJOR MAÑANA
            </p>
            <span className="h-px w-[34px]" style={{ backgroundColor: 'rgba(32,56,74,0.5)' }} />
          </div>
        </div>
      </div>
    </div>
  );
}
