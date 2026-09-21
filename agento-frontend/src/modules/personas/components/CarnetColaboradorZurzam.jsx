import { colorForName, initialsForName } from '../../../utils/avatarColor';
import CarnetBarcode from './CarnetBarcode';
import decoracion from './carnetZurzamAssets/decoracion.svg';
import logoZurzam from './carnetZurzamAssets/logo-zurzam.svg';
import anilloNavy from './carnetZurzamAssets/anillo-navy.svg';
import anilloAzul from './carnetZurzamAssets/anillo-azul.svg';

const NAVY = '#031c36';

/**
 * Plantilla de carnet EXCLUSIVA para Zurzam (diseño Figma "Foto check",
 * nodo 117:891 del archivo). Verificado campo por campo contra Figma
 * (get_design_context/get_metadata/download_assets) el 2026-09-18:
 *   - Background (117:892): blanco, rounded-[28px], SIN borde — no lleva
 *     ningún trazo de color, a diferencia de lo que parecía en una captura
 *     de referencia.
 *   - Decoration (117:893): el `decoracion.svg` local NO coincidía con el
 *     export real de Figma (tenía otras figuras, en otro sistema de
 *     coordenadas de sprite recortado). Se reemplazó por el export real
 *     descargado con `download_assets`, ya en coordenadas directas de la
 *     tarjeta (viewBox 0 0 540 860) — por eso ya no requiere ningún
 *     recorte/offset, va en left:0 top:0 a tamaño completo de la tarjeta.
 *   - Brand/Zurzam Logo (117:903/117:904), Group 3 con los dos "Ring
 *     Accent" + la foto (117:914/915/916/917), y el bloque de texto
 *     (117:918-922, color #031c36, "Cargo:"/"DNI:" en semibold + valor en
 *     regular) coinciden exactamente con los valores ya calculados acá
 *     (confirmado además descargando esos 3 assets reales y comparándolos
 *     byte a byte contra los locales: son idénticos, no requirieron cambio).
 *
 * Todo el posicionamiento va en `style` con left/top/width/height en px
 * (nunca la utilidad `inset-[...]` de Tailwind con shorthand de 4 valores):
 * esta plantilla era la única que usaba ese patrón y las imágenes no
 * quedaban ancladas donde debían — el resto de plantillas (Zazu, Box
 * Prime) ya usa exclusivamente `style` inline para esto, así que se unificó
 * al mismo mecanismo comprobado.
 *
 * Tamaño 540x860px, proporción 54:86 (tarjeta PVC física) — ver el mismo
 * comentario en CarnetColaboradorZazu sobre por qué no hace falta que
 * coincida con el ancho de CarnetColaborador (260px).
 */
export default function CarnetColaboradorZurzam({ colaborador, fotoUrl }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative overflow-hidden rounded-[28px] bg-white"
      style={{ width: 540, height: 860 }}
    >
      <img src={decoracion} alt="" className="absolute" style={{ left: 0, top: 0, width: 540, height: 860 }} />

      <div className="relative z-10 flex w-full flex-col items-center gap-10 pt-[39px]">
        {/* Frame 4 real (Brand + Group 3): gap propio de 32px, distinto del
          gap de 40px hacia el bloque de texto — por eso va en su propio
          contenedor en vez de heredar el gap-10 del padre. */}
        <div className="flex w-full flex-col items-center gap-8">
          <div className="flex w-full flex-col items-center gap-8">
            <div className="relative h-[120px] w-[405px]">
              <img
                src={logoZurzam}
                alt="Zurzam"
                className="absolute"
                style={{ left: 123.1, top: 7.9, width: 158.25, height: 108.77 }}
              />
            </div>

            <div className="relative" style={{ width: 281.6, height: 281.6 }}>
              <img src={anilloNavy} alt="" className="absolute" style={{ left: 0.6, top: 0, width: 148.67, height: 128.99 }} />
              <img src={anilloAzul} alt="" className="absolute" style={{ left: 156.2, top: 153.7, width: 124.67, height: 126.9 }} />
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
        </div>

        <div className="flex items-center justify-center">
          <CarnetBarcode valor={colaborador.numero_documento} color={NAVY} />
        </div>
      </div>
    </div>
  );
}
