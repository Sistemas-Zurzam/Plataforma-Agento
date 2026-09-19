import { colorForName, initialsForName } from "../../../utils/avatarColor";
import CarnetBarcode from "./CarnetBarcode";
import header from "./carnetZazuAssets/header.svg";
import decoracionIzquierda from "./carnetZazuAssets/decoracion-onda-izquierda.svg";
import decoracionDerecha from "./carnetZazuAssets/decoracion-onda-derecha.svg";
import footer from "./carnetZazuAssets/footer.svg";
import logoZazu from "./carnetZazuAssets/logo-zazu.svg";

const MORADO = "#560591";

/**
 * Plantilla de carnet EXCLUSIVA para Zazu Express (diseño Figma "Foto
 * check"), a diferencia de CarnetColaborador (genérico, parametrizado por
 * color/logo de cualquier empresa). El wordmark y los eslóganes laterales
 * son copy de marca propio de Zazu — no existe un campo en `empresas` para
 * personalizarlos, así que van fijos en este componente en vez de venir de
 * `colaborador.empresa`.
 *
 * Igual que CarnetColaborador, el tamaño acá (540x860px, proporción 54:86 —
 * tamaño físico de tarjeta PVC tipo Epson L8050) es la VISTA PREVIA en
 * pantalla; VerCarnetModal::imprimirCarnet() escala este mismo diseño al
 * tamaño físico exacto al imprimir a partir del ancho real renderizado, así
 * que no hace falta que coincida con el ancho de CarnetColaborador (260px).
 */
export default function CarnetColaboradorZazu({ colaborador, fotoUrl }) {
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? "";
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? "";
  const nombreCarnet =
    `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative flex flex-col items-center overflow-hidden rounded-[32px] bg-white shadow-lg"
      style={{ width: 540, height: 860 }}
    >
      <img src={header} alt="" className="absolute top-0 left-0 w-full" />
      <img
        src={decoracionIzquierda}
        alt=""
        className="absolute"
        style={{ left: -5, top: 112, width: 176.7 }}
      />
      <img
        src={decoracionDerecha}
        alt=""
        className="absolute"
        style={{ right: -5, top: 115, width: 175.8 }}
      />

      <div className="relative z-10 flex w-full flex-col items-center gap-10.25 px-6 pt-18.5 pb-16">
        <div className="flex w-full flex-col items-center gap-8">
          <img
            src={logoZazu}
            alt="Zazu Express"
            className="h-[82px] w-[221px]"
          />

          <div className="relative flex w-full flex-col items-center gap-8">
            <div className="relative h-[309px] w-[285px] shrink-0 overflow-hidden rounded-[24px] border-4 border-[#e1cfee]">
              {fotoUrl ? (
                <img
                  src={fotoUrl}
                  alt={colaborador.nombre_completo}
                  className="h-full w-full object-cover"
                />
              ) : (
                <div
                  className="flex h-full w-full items-center justify-center text-4xl font-bold text-white"
                  style={{
                    backgroundColor: colorForName(colaborador.nombre_completo),
                  }}
                >
                  {initialsForName(colaborador.nombre_completo)}
                </div>
              )}
            </div>

            <div className="flex w-full flex-col items-center gap-4">
              <div className="flex flex-col items-center gap-3 text-center">
                <p className="text-[40px] leading-none font-bold text-gray-900">
                  {nombreCarnet}
                </p>
                <p className="text-[20px] text-[#1f252e]">
                  <span className="font-semibold">DNI:</span>{" "}
                  {colaborador.numero_documento}
                </p>
                <p className="text-[20px] text-[#1f252e]">
                  <span className="font-semibold">Cargo:</span>{" "}
                  {colaborador.cargo ?? "Colaborador"}
                </p>
              </div>

              <div className="flex items-center justify-center">
                <CarnetBarcode valor={colaborador.numero_documento} />
              </div>
            </div>

            <div className="absolute top-[46px] left-5 w-[68px]">
              {["PERSONAS", "QUE", "MUEVEN", "MÁS"].map((linea) => (
                <p
                  key={linea}
                  className="text-[10px] font-semibold tracking-[1.4px] text-[#560591]"
                >
                  {linea}
                </p>
              ))}
              <span
                className="mt-1 block h-[2px] w-7"
                style={{ backgroundColor: MORADO }}
              />
            </div>

            <div className="absolute top-[229px] right-5 w-[90px] text-right">
              {["IDEAS", "EN CADA", "DESTINO"].map((linea) => (
                <p
                  key={linea}
                  className="text-[10px] font-semibold tracking-[1.4px] text-[#560591]"
                >
                  {linea}
                </p>
              ))}
              <span
                className="mt-1 ml-auto block h-[2px] w-7"
                style={{ backgroundColor: MORADO }}
              />
            </div>
          </div>
        </div>

        <div className="-mt-4 flex items-center gap-3">
          <span
            className="h-[2px] w-[60px]"
            style={{ backgroundColor: MORADO }}
          />
          <p className="text-[14px] font-medium tracking-[1.6px] whitespace-nowrap text-[#1f252e]">
            UN MUNDO MÁS CERCA
          </p>
          <span
            className="h-[2px] w-[60px]"
            style={{ backgroundColor: MORADO }}
          />
        </div>
      </div>

      {/* La imagen real mide 155.6px de alto pero en Figma se posiciona casi
        fuera de la tarjeta (top ~806-811 de 860) — solo asoma una franja de
        ~50px en la esquina; por eso NO va anclada a bottom-0 (eso mostraba
        la onda completa, tapando el código de barras). Dos copias, una
        reflejada, para el acento simétrico en ambas esquinas. */}
      <img
        src={footer}
        alt=""
        className="absolute left-0"
        style={{ top: 810.72, width: 540 }}
      />
      <img
        src={footer}
        alt=""
        className="absolute"
        style={{ top: 805.72, left: 18, width: 540, transform: "scaleX(-1)" }}
      />
    </div>
  );
}
