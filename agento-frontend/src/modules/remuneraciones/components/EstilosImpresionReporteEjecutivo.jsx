import { useEffect } from 'react';

const RAIZ = '.reporte-ejecutivo-print-root';

// A4 horizontal, mismo margen que el @page de abajo — si uno cambia, el otro
// tiene que seguirlo o el cálculo de zoom queda desincronizado del layout real.
const ANCHO_PAGINA_MM = 297;
const MARGEN_PAGINA_MM = 10;
const PX_POR_MM = 96 / 25.4; // 1in = 96px por spec CSS, siempre.
const ANCHO_DISPONIBLE_PX = (ANCHO_PAGINA_MM - 2 * MARGEN_PAGINA_MM) * PX_POR_MM;
// Piso de legibilidad: con muchísimos conceptos la tabla puede seguir más
// ancha que la hoja, pero nunca se encoge a ilegible.
const ZOOM_MINIMO = 0.4;

/**
 * CSS de impresión para el Reporte Ejecutivo de Remuneraciones — mismo
 * mecanismo que EstilosImpresionBoleta (window.print del navegador, para que
 * el usuario elija "Guardar como PDF"), pero para una TABLA larga de varias
 * páginas en vez de un documento de una sola hoja: con el encabezado de la
 * tabla repitiéndose en cada página impresa y sin cortar una fila entre dos
 * páginas.
 *
 * Ajuste al ANCHO de la hoja: la tabla tiene una columna por concepto
 * pagado (bonos, HE, descuentos…), así que su ancho varía por período. Justo
 * antes de imprimir se mide su ancho real y se aplica `zoom` al contenedor
 * para que quepa en el ancho de una hoja A4 horizontal (mismo criterio que
 * EstilosImpresionBoleta con el alto). Sin esto el navegador recorta las
 * columnas que no caben.
 *
 * Este modal se abre DESDE DENTRO de ResumenContableModal, que sigue montado
 * (oculto) detrás — si el reseteo de position:static de .ant-modal-wrap/mask
 * no se acota a ESTE modal (rootClassName="reporte-ejecutivo-print-root",
 * ver el <Modal> de este componente), también saca al padre de su
 * position:fixed. Un elemento fixed oculto no ocupa espacio en el flujo,
 * pero uno static sí — el contenido (oculto) de ResumenContableModal
 * terminaba insertándose ANTES del nuestro y empujaba la tabla real a la
 * página 2, dejando la página 1 completamente en blanco.
 */
export default function EstilosImpresionReporteEjecutivo({ targetId }) {
  useEffect(() => {
    const contenedor = () => document.getElementById(targetId);
    const ajustar = () => {
      const el = contenedor();
      const tabla = el?.querySelector('table');
      if (!el || !tabla) return;
      el.style.zoom = '';
      const ancho = tabla.scrollWidth;
      const factor = ancho > ANCHO_DISPONIBLE_PX ? Math.max(ZOOM_MINIMO, ANCHO_DISPONIBLE_PX / ancho) : 1;
      if (factor < 1) el.style.zoom = String(factor);
    };
    const revertir = () => {
      const el = contenedor();
      if (el) el.style.zoom = '';
    };

    const mediaImpresion = window.matchMedia('print');
    const alCambiarMedia = (evento) => (evento.matches ? ajustar() : revertir());
    mediaImpresion.addEventListener('change', alCambiarMedia);
    window.addEventListener('beforeprint', ajustar);
    window.addEventListener('afterprint', revertir);

    return () => {
      mediaImpresion.removeEventListener('change', alCambiarMedia);
      window.removeEventListener('beforeprint', ajustar);
      window.removeEventListener('afterprint', revertir);
      revertir();
    };
  }, [targetId]);

  return (
    <style>{`
      @page { margin: ${MARGEN_PAGINA_MM}mm; size: A4 landscape; }
      @media print {
        #root { display: none !important; }
        body * { visibility: hidden; }
        #${targetId}, #${targetId} * { visibility: visible; }
        ${RAIZ} .ant-modal-header, ${RAIZ} .ant-modal-footer { display: none !important; }
        ${RAIZ} .ant-modal-mask, ${RAIZ} .ant-modal-wrap,
        ${RAIZ} .ant-modal, ${RAIZ} .ant-modal-content, ${RAIZ} .ant-modal-body {
          position: static !important;
          inset: auto !important;
          top: auto !important;
          overflow: visible !important;
          height: auto !important;
          max-height: none !important;
          box-shadow: none !important;
        }
        ${RAIZ} .ant-modal, ${RAIZ} .ant-modal-content {
          width: 100% !important;
          max-width: 100% !important;
          padding: 0 !important;
          margin: 0 !important;
        }
        #${targetId} { position: static; width: 100%; }
        #${targetId} .reporte-ejecutivo-scroll { overflow: visible !important; }
        #${targetId} * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        #${targetId} table { width: 100%; border-collapse: collapse; }
        #${targetId} thead { display: table-header-group; }
        #${targetId} tr { break-inside: avoid; }
        #${targetId} tfoot { display: table-footer-group; }
      }
    `}</style>
  );
}
