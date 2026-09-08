/**
 * CSS de impresión compartido entre BoletaImprimibleModal (una boleta) y
 * BoletasImprimirMasivoModal (varias) — mismo mecanismo en ambos:
 * window.print() con esto ocultando el resto de la página, para que el
 * usuario elija "Guardar como PDF" desde el diálogo del navegador.
 *
 * targetId es el contenedor visible durante la impresión (distinto en cada
 * modal). paginaPorDocumento agrega el salto de página entre boletas —
 * solo tiene efecto si existe algún elemento .boleta-salto-pagina.
 */
export default function EstilosImpresionBoleta({ targetId }) {
  return (
    <style>{`
      /* Margen por defecto del navegador (~2.5cm) sobra espacio en una
         boleta de una sola página — con esto suele alcanzar para que
         entre completa sin partirse en 2. */
      @page { margin: 6mm; }
      @media print {
        /* #root es el resto de la app detrás del modal (la tabla de
           planilla, tarjetas, etc.) — con visibility:hidden solo se
           invisibiliza, pero sigue ocupando su alto real en el layout, lo
           que infla la página impresa y genera una página extra. display:none
           lo saca del flujo por completo. */
        #root { display: none !important; }
        body * { visibility: hidden; }
        #${targetId}, #${targetId} * { visibility: visible; }
        /* El wrapper de Ant Design (.ant-modal-wrap) es position:fixed +
           overflow:auto acotado al viewport, y .ant-modal es
           position:relative — eso convierte a .ant-modal en el ancestro
           posicionado de #${targetId}, así que un position:absolute
           ahí quedaba recortado por el overflow:auto del wrapper (se
           perdía el encabezado de arriba y el texto del pie se
           superponía). Se neutraliza toda la cadena a static/visible para
           que el contenido fluya en la página impresa como un documento
           normal, sin las restricciones de "ventana modal en pantalla". */
        .ant-modal-root, .ant-modal-mask, .ant-modal-wrap, .ant-modal,
        .ant-modal-content, .ant-modal-body {
          position: static !important;
          inset: auto !important;
          top: auto !important;
          overflow: visible !important;
          height: auto !important;
          max-height: none !important;
          box-shadow: none !important;
        }
        /* El Modal tiene un width fijo para pantalla (prop "width" del
           componente) — sin resetearlo también, #${targetId} al 100%
           seguía significando "100% del ancho fijo", no de la hoja
           completa, y quedaba una columna angosta centrada rodeada de
           margen en blanco. */
        .ant-modal, .ant-modal-content {
          width: 100% !important;
          max-width: 100% !important;
          padding: 0 !important;
          margin: 0 !important;
        }
        #${targetId} { position: static; width: 100%; }
        /* Los navegadores no imprimen fondos de color por defecto (para
           ahorrar tinta) — sin esto el header/tarjetas de marca salen en
           blanco y negro al "Guardar como PDF". */
        #${targetId} * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Impresión masiva: una boleta por página — nunca dos boletas
           compartiendo hoja, ni una hoja en blanco extra al final. */
        .boleta-salto-pagina { break-after: page; }
        .boleta-salto-pagina:last-child { break-after: auto; }
      }
    `}</style>
  );
}
