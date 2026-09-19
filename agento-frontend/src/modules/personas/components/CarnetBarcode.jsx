import { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';

/**
 * Código de barras REAL (Code 128, subset C) para marcación de asistencia
 * por carnet — a diferencia del patrón de barras decorativo que traían las
 * plantillas (copiado tal cual del mockup de Figma, "Placeholder — NOT
 * PRODUCTION"), `valor` acá ES `colaborador.numero_documento` (decisión
 * explícita del negocio: sin credencial generada/revocable por separado —
 * ver RegistrarMarcacionCarnetService::registrar() en el backend).
 *
 * `CODE128C` (subset numérico, empaqueta 2 dígitos por símbolo) se usa
 * cuando `valor` es solo dígitos en cantidad PAR — el caso más compacto,
 * ideal para el bloque inferior de una tarjeta CR80 (54mm). Cuando no
 * cumple eso (un DNI de 9 dígitos como "006884947", cantidad impar; o un
 * carné de extranjería con letras) se cae a `CODE128` general, que
 * codifica el valor exacto igual, solo que un poco menos compacto — nunca
 * se deja de dibujar el código por esto. El try/catch de abajo sigue ahí
 * como última red: si aun así jsbarcode lanza (ej. un carácter no
 * imprimible), se prefiere no dibujar nada a que la excepción rompa el
 * modal completo.
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
 * displayValue siempre en false: el texto legible debajo del código no
 * duplica el número de documento (ya se muestra aparte en el carnet).
 */
export default function CarnetBarcode({ valor, anchoBarra = 2.7, alto = 110, color = '#000000' }) {
  const svgRef = useRef(null);

  useEffect(() => {
    if (!svgRef.current || !valor) return;

    const esNumericoPar = /^\d+$/.test(valor) && valor.length % 2 === 0;

    try {
      JsBarcode(svgRef.current, valor, {
        format: esNumericoPar ? 'CODE128C' : 'CODE128',
        width: anchoBarra,
        height: alto,
        displayValue: false,
        margin: 0,
        background: 'transparent',
        lineColor: color,
      });
    } catch (error) {
      console.error('No se pudo generar el código de barras: el número de documento no tiene el formato esperado (solo dígitos, cantidad par).', error);
    }
  }, [valor, anchoBarra, alto, color]);

  if (!valor) return null;

  return <svg ref={svgRef} role="img" aria-label="Código de barras de la credencial de acceso" />;
}
