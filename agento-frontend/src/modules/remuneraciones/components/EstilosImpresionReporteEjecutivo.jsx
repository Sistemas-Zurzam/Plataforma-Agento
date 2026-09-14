const RAIZ = '.reporte-ejecutivo-print-root';

/**
 * CSS de impresión para el Reporte Ejecutivo de Remuneraciones — mismo
 * mecanismo que EstilosImpresionBoleta (window.print del navegador, para que
 * el usuario elija "Guardar como PDF"), pero para una TABLA larga de varias
 * páginas en vez de un documento de una sola hoja: sin el ajuste de zoom,
 * con el encabezado de la tabla repitiéndose en cada página impresa y sin
 * cortar una fila entre dos páginas.
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
  return (
    <style>{`
      @page { margin: 10mm; size: A4 landscape; }
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
        #${targetId} * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        #${targetId} table { width: 100%; border-collapse: collapse; }
        #${targetId} thead { display: table-header-group; }
        #${targetId} tr { break-inside: avoid; }
        #${targetId} tfoot { display: table-footer-group; }
      }
    `}</style>
  );
}
