import { DownloadOutlined, PrinterOutlined } from '@ant-design/icons';
import { App, Button, Modal, Segmented } from 'antd';
import { toPng } from 'html-to-image';
import { useEffect, useState } from 'react';
import api from '../../../services/api';
import { useColaboradores } from '../hooks/useColaboradores';
import CarnetColaboradorReverso from './CarnetColaboradorReverso';
import {
  ALTO_NATIVO_PLANTILLA_ANCHA,
  ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA,
  ESCALA_VISTA_PREVIA,
  PLANTILLAS_ANCHAS,
  resolverPlantillaCarnet,
} from './plantillasCarnet';

/**
 * Compartido entre la ficha del colaborador y la fila "Acciones" del
 * listado — evita duplicar la lógica de impresión en 2 lugares. Siempre
 * busca la foto por su cuenta (ignora si el colaborador ya trae
 * `documentos` cargado o no): el listado no eager-carga esa relación, así
 * que depender de ella ahí rompería; el endpoint ya responde null si no
 * hay foto, así que el intento extra es inofensivo.
 */
export default function VerCarnetModal({ colaborador, onClose }) {
  const { fetchFotoPerfil } = useColaboradores();
  const { message } = App.useApp();
  const [fotoUrl, setFotoUrl] = useState(null);
  const [cara, setCara] = useState('frente');
  const [credencialActiva, setCredencialActiva] = useState(false);
  const [colaboradorPrevio, setColaboradorPrevio] = useState(colaborador);

  // Reinicia la foto y el estado de la credencial cuando cambia
  // `colaborador` (se abre para otro, o se cierra) ajustándolo durante el
  // render en vez de en un efecto — el patrón que React recomienda para
  // esto.
  if (colaborador !== colaboradorPrevio) {
    setColaboradorPrevio(colaborador);
    setFotoUrl(null);
    setCredencialActiva(false);
    setCara('frente');
  }

  useEffect(() => {
    if (!colaborador) return undefined;

    let cancelado = false;
    fetchFotoPerfil(colaborador.id).then((blob) => {
      if (!cancelado && blob) setFotoUrl(URL.createObjectURL(blob));
    });
    return () => {
      cancelado = true;
    };
  }, [colaborador, fetchFotoPerfil]);

  useEffect(() => () => { if (fotoUrl) URL.revokeObjectURL(fotoUrl); }, [fotoUrl]);

  // Este modal NUNCA tiene el token plano (solo existe una vez, al
  // generar/regenerar — ver GenerarCredencialCarnetModal), pero sí necesita
  // saber si HAY una credencial activa para no decir "sin habilitar"
  // cuando en realidad el carnet funciona y solo no se puede mostrar el
  // código acá.
  useEffect(() => {
    if (!colaborador) return;

    api.get(`/colaboradores/${colaborador.id}/credencial-carnet`)
      .then(({ data }) => setCredencialActiva(Boolean(data.data?.tiene_credencial_activa)));
  }, [colaborador]);

  /**
   * Clona TODOS los <style>/<link rel="stylesheet"> del documento actual
   * hacia una ventana nueva — así el carnet impreso mantiene exactamente
   * los mismos estilos (Tailwind incluido) sin depender de adivinar rutas
   * de build. @page fuerza el tamaño físico real de la tarjeta para
   * impresora de PVC tipo Epson L8050 (54 × 86 mm).
   *
   * En vez de sobreescribir el width/height del carnet directamente (lo
   * que dejaba a los hijos con sus tamaños en px fijos sin escalar,
   * recortando contenido si la proporción no calzaba exacto), se escala
   * el diseño completo con `transform: scale()` — el mismo diseño que se
   * ve en pantalla (proporción 54:86, sin importar si son los 260px de
   * CarnetColaborador o los 540px de CarnetColaboradorZazu) se reduce
   * uniformemente al tamaño físico exacto usando el ancho ya renderizado
   * (`offsetWidth`), así ningún elemento interno se desalinea ni se corta.
   */
  const imprimirCarnet = () => {
    const contenedor = document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return;
    const anchoRenderizado = contenedor.offsetWidth;

    const ventana = window.open('', '_blank', 'width=380,height=640');
    if (!ventana) {
      message.error('El navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes para este sitio.');
      return;
    }

    const estilos = Array.from(document.querySelectorAll('style, link[rel="stylesheet"]'))
      .map((el) => el.outerHTML)
      .join('\n');

    ventana.document.write(`<!doctype html>
      <html>
        <head>
          ${estilos}
          <style>
            @page { size: 54mm 86mm; margin: 0; }
            html, body { margin: 0; padding: 0; }
            /* El código de barras y varias líneas/formas decorativas de las
              plantillas (Zazu, Box Prime, Texajo...) se dibujan con
              background-color puro, sin imagen ni borde — los navegadores no
              imprimen background-color por defecto salvo que el usuario
              marque "Gráficos de fondo" en el diálogo de impresión. Esto lo
              fuerza sin depender de esa casilla. */
            * {
              -webkit-print-color-adjust: exact !important;
              print-color-adjust: exact !important;
            }
            #carnet-imprimible-pagina { width: 54mm; height: 86mm; overflow: hidden; }
            #carnet-colaborador-imprimible {
              transform: scale(calc(54mm / ${anchoRenderizado}px));
              transform-origin: top left;
              box-shadow: none !important;
              border-radius: 0 !important;
            }
          </style>
        </head>
        <body><div id="carnet-imprimible-pagina">${contenedor.outerHTML}</div></body>
      </html>`);
    ventana.document.close();
    // Título asignado como propiedad (no interpolado en el HTML crudo de
    // arriba): document.title siempre se trata como texto plano, así un
    // nombre de colaborador con `</title><script>` no puede inyectar
    // markup/JS en esta ventana — a diferencia del <title> de antes, que sí
    // era vulnerable a eso.
    ventana.document.title = `Carnet — ${colaborador.nombre_completo}${cara === 'reverso' ? ' (reverso)' : ''}`;
    ventana.onload = () => {
      ventana.focus();
      ventana.print();
    };
  };

  // La impresora física (Epson L8050, tarjetas PVC) se imprime desde el
  // software Epson Photo+, no desde el diálogo de impresión del navegador
  // — por eso hace falta poder descargar el carnet como imagen en vez de
  // depender únicamente de `imprimirCarnet()`. pixelRatio:3 da ~1620px de
  // ancho (más que suficiente para el tamaño físico de 54mm), sin generar
  // un archivo desproporcionadamente pesado.
  const descargarPng = async () => {
    const contenedor = document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return;

    // La foto de perfil se pasa como URL `blob:` (ver el efecto de arriba)
    // — html-to-image, al clonar el árbol, vuelve a pedir por red cada
    // <img> para incrustarlo como data-URI, y si el blob ya no está
    // disponible en ese momento falla (`net::ERR_FILE_NOT_FOUND`), aunque
    // en pantalla el <img> siga mostrándola con normalidad porque el
    // navegador ya la tiene decodificada en memoria. El arreglo: dibujar
    // ESE <img> ya cargado directo a un canvas (sin volver a pedirlo) y
    // usar esa imagen como fuente temporal durante la captura.
    const imgFoto = contenedor.querySelector('img[src^="blob:"]');
    const fotoSrcPrevio = imgFoto?.src;
    if (imgFoto?.complete && imgFoto.naturalWidth > 0) {
      const lienzo = document.createElement('canvas');
      lienzo.width = imgFoto.naturalWidth;
      lienzo.height = imgFoto.naturalHeight;
      lienzo.getContext('2d').drawImage(imgFoto, 0, 0);
      imgFoto.src = lienzo.toDataURL('image/png');
    }

    // La tarjeta raíz recorta con `overflow-hidden` + esquinas redondeadas
    // los elementos decorativos que sobresalen a propósito (footer, ondas,
    // blobs...). html-to-image, al reconstruir el árbol dentro de un
    // <foreignObject>, no recorta bien ese contenido cuando el propio nodo
    // capturado es el que tiene `overflow:hidden` — el resultado es que
    // todo lo posicionado fuera de los 540x860 "a propósito pero recortado"
    // (el footer de Zazu, las ondas/blobs de Zurzam/Livex) sale en blanco.
    // Se quita el recorte solo durante la captura: como de todos modos se
    // pide un canvas de 540x860, cualquier cosa fuera de ese rectángulo
    // queda fuera del PNG igual, así que el resultado visual no cambia.
    const overflowPrevio = contenedor.style.overflow;
    contenedor.style.overflow = 'visible';

    try {
      const dataUrl = await toPng(contenedor, {
        pixelRatio: 3,
        width: contenedor.offsetWidth,
        height: contenedor.offsetHeight,
      });
      const enlace = document.createElement('a');
      enlace.href = dataUrl;
      enlace.download = `carnet-${colaborador.nombre_completo}${cara === 'reverso' ? '-reverso' : ''}.png`;
      enlace.click();
    } catch {
      message.error('No se pudo generar la imagen del carnet.');
    } finally {
      contenedor.style.overflow = overflowPrevio;
      if (imgFoto && fotoSrcPrevio) imgFoto.src = fotoSrcPrevio;
    }
  };

  // resolverPlantillaCarnet() solo elige entre componentes ya definidos a
  // nivel de módulo (PLANTILLAS_POR_EMPRESA/PLANTILLA_GENERICA) — la
  // referencia es estable entre renders aunque el lint no pueda verlo a
  // través de la llamada a función.
  const CarnetTemplate = resolverPlantillaCarnet(colaborador?.empresa?.plantilla_carnet);
  const esPlantillaAncha = PLANTILLAS_ANCHAS.has(CarnetTemplate);

  return (
    <Modal
      title="Carnet de colaborador"
      open={Boolean(colaborador)}
      onCancel={onClose}
      footer={[
        <Button key="cerrar" onClick={onClose}>Cerrar</Button>,
        <Button key="descargar" icon={<DownloadOutlined />} onClick={descargarPng}>Descargar PNG</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={imprimirCarnet}>Imprimir</Button>,
      ]}
      width={420}
      centered
    >
      {colaborador && (
        <div className="flex flex-col items-center gap-4 py-2">
          <Segmented
            options={[{ label: 'Frente', value: 'frente' }, { label: 'Reverso', value: 'reverso' }]}
            value={cara}
            onChange={setCara}
          />
          {cara === 'frente' ? (
            esPlantillaAncha ? (
              <div style={{ width: ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA, height: ALTO_NATIVO_PLANTILLA_ANCHA * ESCALA_VISTA_PREVIA }}>
                <div style={{ transform: `scale(${ESCALA_VISTA_PREVIA})`, transformOrigin: 'top left' }}>
                  {/* eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet() */}
                  <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} credencialActiva={credencialActiva} />
                </div>
              </div>
            ) : (
              // eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet()
              <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} credencialActiva={credencialActiva} />
            )
          ) : (
            <CarnetColaboradorReverso colaborador={colaborador} />
          )}
        </div>
      )}
    </Modal>
  );
}
