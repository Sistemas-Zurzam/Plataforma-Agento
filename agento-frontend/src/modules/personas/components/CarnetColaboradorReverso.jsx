import Barcode from 'react-barcode';

/**
 * Reverso del carnet — mismo tamaño físico que el frente
 * (`CarnetColaborador`: 260x414px, proporción 54:86) para que
 * `VerCarnetModal` pueda imprimir cualquiera de las dos caras con el mismo
 * escalado a 54x86mm sin tocar la lógica de impresión.
 *
 * El código de barras (Code128 — soporta DNI numérico y también carné de
 * extranjería/pasaporte alfanumérico, ver TIPO_DOCUMENTO_OPTIONS) codifica
 * directamente `numero_documento`: el mismo valor que el futuro lector de
 * marcación leerá para resolver al colaborador, sin necesidad de generar
 * ni almacenar un código interno aparte.
 *
 * El SVG se escala vía CSS (`w-full h-auto` sobre el propio `<svg>` que
 * expone `react-barcode`) en vez de fijar un ancho de módulo en píxeles,
 * porque el largo del código varía según el tipo de documento (8 dígitos
 * en un DNI vs. varios caracteres alfanuméricos en un CE/pasaporte) y así
 * siempre entra dentro del ancho de la tarjeta sin recortarse.
 */
export default function CarnetColaboradorReverso({ colaborador }) {
  const numeroDocumento = colaborador.numero_documento?.trim() ?? '';

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative flex flex-col items-center justify-center gap-3 overflow-hidden rounded-3xl bg-white px-7 shadow-lg"
      style={{ width: 260, height: 414 }}
    >
      {numeroDocumento ? (
        <>
          <div className="w-full [&_svg]:h-auto [&_svg]:w-full">
            <Barcode value={numeroDocumento} format="CODE128" height={70} margin={0} displayValue={false} />
          </div>
          <p className="text-sm font-semibold tracking-[0.2em] text-gray-700">{numeroDocumento}</p>
        </>
      ) : (
        <p className="text-center text-sm text-gray-400">Sin número de documento registrado</p>
      )}
    </div>
  );
}
