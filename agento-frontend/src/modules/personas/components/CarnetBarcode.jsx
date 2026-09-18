import { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';

/**
 * Código de barras REAL (Code 128, subset C) para marcación de asistencia
 * por carnet — a diferencia del patrón de barras decorativo que traían las
 * plantillas (copiado tal cual del mockup de Figma, "Placeholder — NOT
 * PRODUCTION"), `valor` acá es la credencial segura (el token de 20 dígitos
 * que imprime el carnet, nunca el DNI ni el ID del colaborador — ver
 * CarnetCredentialService en el backend).
 *
 * `format: 'CODE128C'` explícito (no `'CODE128'`, que autodetecta el subset
 * según el contenido): CODE128C empaca 2 dígitos por símbolo (~11 módulos
 * cada 2), la única razón por la que un token de 20 dígitos entra en el
 * bloque inferior de una tarjeta CR80 (54mm) — con autodetección, un cambio
 * futuro del formato del token podría silenciosamente caer a un subset menos
 * denso y desbordar la tarjeta sin ningún error visible. CODE128C exige
 * además que `valor` tenga una cantidad PAR de dígitos y NADA más que
 * dígitos — jsbarcode lanza si no, por eso el try/catch de abajo: si algún
 * día llega un valor que no cumpla el formato vigente, se prefiere no
 * dibujar nada a que la excepción rompa el modal completo.
 *
 * anchoBarra/alto (px) son unidades SVG del ORIGEN de cada plantilla, que
 * luego se imprime a tamaño físico real (ver el comentario de
 * VerCarnetModal::imprimirCarnet()) — no son mm directos. Los valores por
 * defecto están calculados para las plantillas de empresa (540px de ancho
 * de tarjeta ≈ 10px/mm): anchoBarra=2.7px ≈ 0.27mm/módulo, alto=110px ≈
 * 11mm — dentro del rango 0.25-0.3mm/módulo y 10-12mm de alto pedido. La
 * plantilla genérica (260px de ancho ≈ 4.815px/mm) pasa sus propios valores
 * escalados (ver CarnetColaborador.jsx) para mantener la misma proporción
 * física, no estos por defecto.
 *
 * displayValue siempre en false: el texto legible debajo del código NUNCA
 * debe mostrar la credencial completa.
 */
export default function CarnetBarcode({ valor, anchoBarra = 2.7, alto = 110, color = '#000000' }) {
  const svgRef = useRef(null);

  useEffect(() => {
    if (!svgRef.current || !valor) return;

    try {
      JsBarcode(svgRef.current, valor, {
        format: 'CODE128C',
        width: anchoBarra,
        height: alto,
        displayValue: false,
        margin: 0,
        background: 'transparent',
        lineColor: color,
      });
    } catch (error) {
      console.error('No se pudo generar el código de barras: el valor no tiene el formato esperado (20 dígitos).', error);
    }
  }, [valor, anchoBarra, alto, color]);

  if (!valor) return null;

  return <svg ref={svgRef} role="img" aria-label="Código de barras de la credencial de acceso" />;
}
