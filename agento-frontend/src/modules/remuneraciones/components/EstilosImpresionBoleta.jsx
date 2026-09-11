import { useEffect } from 'react';

// A4 vertical, mismo margen que el @page de abajo — si uno cambia, el otro
// tiene que seguirlo o el cálculo de zoom queda desincronizado del layout real.
const ALTURA_PAGINA_MM = 297;
const MARGEN_PAGINA_MM = 4;
const PX_POR_MM = 96 / 25.4; // 1in = 96px por spec CSS, siempre — no depende del DPI real de la impresora.
const ALTURA_DISPONIBLE_PX = (ALTURA_PAGINA_MM - 2 * MARGEN_PAGINA_MM) * PX_POR_MM;
const ZOOM_MINIMO = 0.7; // piso de legibilidad — por debajo de esto una boleta con MUCHO contenido puede seguir desbordando a una 2da página, pero nunca se encoge a ilegible.

/**
 * CSS de impresión compartido entre BoletaImprimibleModal (una boleta) y
 * BoletasImprimirMasivoModal (varias) — mismo mecanismo en ambos:
 * window.print() con esto ocultando el resto de la página, para que el
 * usuario elija "Guardar como PDF" desde el diálogo del navegador.
 *
 * targetId es el contenedor visible durante la impresión (distinto en cada
 * modal).
 *
 * Ajuste automático a una sola página: los paddings/gaps ya comprimidos
 * (print:py-*, print:gap-*) en BoletaDocumento no alcanzan a garantizar que
 * SIEMPRE quepa en una hoja — una boleta con varios reintegros puede seguir
 * siendo más alta que la página. Justo antes de imprimir (matchMedia
 * 'print' — más confiable que beforeprint en algunos motores, se escuchan
 * ambos por las dudas) se mide el alto real de CADA .boleta-documento
 * presente (una o varias, en la impresión masiva cada una con su propio
 * factor) y se le aplica `zoom` para que quepa exacto en una página. `zoom`
 * (no transform: scale) porque re-layoutea el subárbol completo — con
 * transform el ancho no se recalcula y sobra margen en blanco a los
 * costados, o el texto queda distorsionado con scaleY.
 */
export default function EstilosImpresionBoleta({ targetId }) {
  useEffect(() => {
    const ajustar = () => {
      document.querySelectorAll('.boleta-documento').forEach((el) => {
        el.style.zoom = '';
        const alto = el.scrollHeight;
        const factor = alto > ALTURA_DISPONIBLE_PX ? Math.max(ZOOM_MINIMO, ALTURA_DISPONIBLE_PX / alto) : 1;
        if (factor < 1) el.style.zoom = String(factor);
      });
    };
    const revertir = () => {
      document.querySelectorAll('.boleta-documento').forEach((el) => { el.style.zoom = ''; });
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
  }, []);

  return (
    <style>{`
      /* Margen por defecto del navegador (~2.5cm) sobra espacio en una
         boleta de una sola página. Si este valor cambia, actualizar
         también MARGEN_PAGINA_MM arriba — el cálculo de zoom asume que
         coinciden. */
      @page { margin: ${MARGEN_PAGINA_MM}mm; }
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
