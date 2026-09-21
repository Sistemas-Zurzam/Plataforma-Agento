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
export default function CarnetColaboradorLivex({ colaborador, fotoUrl }) {
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
      {/* left/top/width/height en px (no `inset` en %) MÁS maxWidth:'none':
        el ancho real (899px) es mayor que el contenedor (540px), así que el
        reset de Tailwind `img{max-width:100%}` seguía aplicando encima del
        `width` inline (son propiedades distintas, `width` no pisa `max-width`)
        y achataba/desplazaba la imagen. Verificado contra el export real de
        Figma (nodo 43:123) el 2026-09-18: estos valores son la caja exacta
        de la unión de las 7 figuras. */}
      <img
        src={formasDecorativas}
        alt=""
        className="absolute"
        style={{ left: -172, top: -77, width: 899, height: 1028, maxWidth: 'none' }}
      />
      <img src={blobInferiorIzquierdo} alt="" className="absolute" style={{ left: -130, top: 756, width: 200, height: 200 }} />

      {/* pt-[33px] fijo en vez de justify-center: en Figma el bloque (nodo
        "Frame 24") va anclado a un y=33 absoluto, no centrado — depender de
        justify-center hacía que el resultado final dependiera de que la
        altura real del texto renderizado en el navegador coincidiera con la
        altura que asume Figma, y no coincidía (el logo terminaba pegado al
        borde superior). Verificado contra get_metadata el 2026-09-18. */}
      {/* gap-[15px] (no los 60px que mide Figma entre el código de barras y
        el pie): el placeholder de Figma para el código de barras mide 80px
        de alto, pero CarnetBarcode usa 110px reales (10-12mm, necesarios
        para que el lector físico lo escanee bien) — esos 30px de más ya se
        "gastan" en el alto real del propio código. Se resta un poco más del
        gap original (30px) para que el pie quede con más aire respecto al
        borde inferior en vez de pegado a él (ajuste visual pedido tras ver
        el resultado ya sin recorte). */}
      <div className="absolute inset-0 flex flex-col items-center gap-[15px] pt-[33px]">
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
            <CarnetBarcode valor={colaborador.numero_documento} color="#1a1f1c" />
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
