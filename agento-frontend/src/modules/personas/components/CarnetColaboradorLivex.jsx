import { colorForName, initialsForName } from '../../../utils/avatarColor';
import CarnetBarcode from './CarnetBarcode';
import formasDecorativas from './carnetLivexAssets/formas-decorativas.svg';
import blobInferiorIzquierdo from './carnetLivexAssets/blob-inferior-izquierdo.svg';
import logoLivex from './carnetLivexAssets/logo-livex.svg';

/**
 * Plantilla de carnet EXCLUSIVA para Livex Agency (diseño Figma "Foto
 * check"), igual que CarnetColaboradorZazu — wordmark y eslogan de marca
 * fijos en el componente (no vienen de `colaborador.empresa`).
 *
 * Tamaño 540x860px, proporción 54:86 (tarjeta PVC física) — ver el mismo
 * comentario en CarnetColaboradorZazu sobre por qué no hace falta que
 * coincida con el ancho de CarnetColaborador (260px): VerCarnetModal
 * escala al imprimir según el ancho real renderizado.
 */
export default function CarnetColaboradorLivex({ colaborador, fotoUrl, credencialToken, credencialActiva }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative overflow-hidden rounded-[40px] bg-[#f7faf7] shadow-[0px_8px_28px_0px_rgba(0,0,0,0.18)]"
      style={{ width: 540, height: 860 }}
    >
      <div className="absolute top-[2px] left-0 rounded-[40px] bg-[#fefefd]" style={{ width: 540, height: 858 }} />
      <img src={formasDecorativas} alt="" className="absolute" style={{ inset: '-8.95% -34.63% -10.58% -31.85%' }} />
      <img src={blobInferiorIzquierdo} alt="" className="absolute" style={{ left: -130, top: 756, width: 200, height: 200 }} />

      <div className="absolute inset-0 flex flex-col items-center justify-center gap-[60px]">
        <div className="flex w-full flex-col items-center gap-10">
          <div className="flex flex-col items-center gap-8">
            <div className="flex w-[285px] flex-col items-center gap-8">
              <img src={logoLivex} alt="Livex Agency" className="h-[109px] w-[200px]" />

              <div className="relative h-[292px] w-full overflow-hidden rounded-[22px] border-2 border-[#a6c2b4]">
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

            <div className="flex flex-col items-center gap-3 text-center text-[#1a1f1c]">
              <p className="text-[40px] leading-none font-bold">{nombreCarnet}</p>
              <p className="text-[20px]">
                <span className="font-semibold">Cargo:</span> {colaborador.cargo ?? 'Colaborador'}
              </p>
              <p className="text-[20px]">
                <span className="font-semibold">DNI:</span> {colaborador.numero_documento}
              </p>
            </div>
          </div>

          <div className="flex items-center justify-center">
            {credencialToken ? (
              <CarnetBarcode valor={credencialToken} color="#1a1f1c" />
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

        <div className="flex flex-col items-center gap-2">
          <p className="text-[14px] font-semibold tracking-[1.4px] text-[#3f6652]">EXPERIENCIAS QUE CONECTAN</p>
          <span className="h-[1.5px] w-[46px] bg-[#6f9a82]" />
        </div>
      </div>
    </div>
  );
}
