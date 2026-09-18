import { colorForName, initialsForName } from '../../../utils/avatarColor';
import CarnetBarcode from './CarnetBarcode';
import decoracion from './carnetZurzamAssets/decoracion.svg';
import logoZurzam from './carnetZurzamAssets/logo-zurzam.svg';
import anilloNavy from './carnetZurzamAssets/anillo-navy.svg';
import anilloAzul from './carnetZurzamAssets/anillo-azul.svg';

const NAVY = '#031c36';

/**
 * Plantilla de carnet EXCLUSIVA para Zurzam (diseño Figma "Foto check"),
 * igual que las demás — wordmark fijo en el componente (no viene de
 * `colaborador.empresa`).
 *
 * Tamaño 540x860px, proporción 54:86 (tarjeta PVC física) — ver el mismo
 * comentario en CarnetColaboradorZazu sobre por qué no hace falta que
 * coincida con el ancho de CarnetColaborador (260px).
 */
export default function CarnetColaboradorZurzam({ colaborador, fotoUrl, credencialToken, credencialActiva }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative overflow-hidden rounded-[28px] bg-white"
      style={{ width: 540, height: 860 }}
    >
      <img src={decoracion} alt="" className="absolute inset-[0_-62.41%_-24.77%_-24.07%]" />

      <div className="relative z-10 flex w-full flex-col items-center gap-10 pt-[39px]">
        <div className="flex w-full flex-col items-center gap-8">
          <div className="relative h-[120px] w-[405px]">
            <img src={logoZurzam} alt="Zurzam" className="absolute inset-[6.61%_30.53%_2.75%_30.39%]" />
          </div>

          <div className="relative" style={{ width: 281.6, height: 281.6 }}>
            <img src={anilloNavy} alt="" className="absolute" style={{ inset: '0 47% 54.2% 0.21%' }} />
            <img src={anilloAzul} alt="" className="absolute" style={{ inset: '54.58% 0.25% 0.36% 55.48%' }} />
            <div className="absolute overflow-hidden rounded-full" style={{ left: 14.3, top: 14.3, width: 253, height: 253 }}>
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
          </div>
        </div>

        <div className="flex flex-col items-center gap-3 text-center" style={{ color: NAVY }}>
          <p className="text-[40px] leading-none font-extrabold">{nombreCarnet}</p>
          <p className="text-[20px] font-semibold">
            Cargo: <span className="font-normal">{colaborador.cargo ?? 'Colaborador'}</span>
          </p>
          <p className="text-[20px] font-semibold">
            DNI: <span className="font-normal">{colaborador.numero_documento}</span>
          </p>
        </div>

        <div className="flex items-center justify-center">
          {credencialToken ? (
            <CarnetBarcode valor={credencialToken} color={NAVY} />
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
      </div>
    </div>
  );
}
