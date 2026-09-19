import { DownloadOutlined, PrinterOutlined, UsbOutlined } from '@ant-design/icons';
import { App, Button, Modal, Segmented, Select, Tooltip, Typography } from 'antd';
import { toPng } from 'html-to-image';
import { useEffect, useRef, useState } from 'react';
import { useColaboradores } from '../hooks/useColaboradores';
import { usePrintServiceLocal } from '../hooks/usePrintServiceLocal';
import CarnetColaboradorReverso from './CarnetColaboradorReverso';
import {
  ALTO_NATIVO_PLANTILLA_ANCHA,
  ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA,
  ESCALA_VISTA_PREVIA,
  PLANTILLAS_ANCHAS,
  resolverPlantillaCarnet,
} from './plantillasCarnet';

function VistaCarnetRanura({ numero, Template, colaborador, fotoUrl, cara, esPlantillaAncha, contenedorRef }) {
  const anchoNativo = cara === 'frente' && esPlantillaAncha ? 540 : 260;
  const altoNativo = cara === 'frente' && esPlantillaAncha ? 860 : 414;
  const anchoVista = 150;
  const escala = anchoVista / anchoNativo;

  return (
    <div className="flex min-w-0 flex-col items-center gap-2">
      <Typography.Text strong>Ranura {numero}</Typography.Text>
      <div
        ref={contenedorRef}
        className="overflow-hidden rounded-md border border-purple-200 bg-white shadow-sm"
        style={{ width: anchoVista, height: altoNativo * escala }}
      >
        <div style={{ transform: `scale(${escala})`, transformOrigin: 'top left' }}>
          {cara === 'frente' ? (
            <Template colaborador={colaborador} fotoUrl={fotoUrl} />
          ) : (
            <CarnetColaboradorReverso colaborador={colaborador} />
          )}
        </div>
      </div>
      <Typography.Text className="max-w-[150px] truncate" title={colaborador.nombre_completo}>
        {colaborador.nombre_completo}
      </Typography.Text>
    </div>
  );
}

/**
 * Compartido entre la ficha del colaborador y la fila "Acciones" del
 * listado — evita duplicar la lógica de impresión en 2 lugares. Siempre
 * busca la foto por su cuenta (ignora si el colaborador ya trae
 * `documentos` cargado o no): el listado no eager-carga esa relación, así
 * que depender de ella ahí rompería; el endpoint ya responde null si no
 * hay foto, así que el intento extra es inofensivo.
 */
export default function VerCarnetModal({ colaborador, onClose }) {
  const { fetchColaboradores, fetchColaborador, fetchFotoPerfil } = useColaboradores();
  const { message } = App.useApp();
  const { disponible: printServiceDisponible, imprimirEnPvc, imprimirDosEnPvc } = usePrintServiceLocal();
  const [fotoUrl, setFotoUrl] = useState(null);
  const [cara, setCara] = useState('frente');
  const [imprimiendoPvc, setImprimiendoPvc] = useState(false);
  const [colaboradorPrevio, setColaboradorPrevio] = useState(colaborador);
  const [modoPvc, setModoPvc] = useState('uno');
  const [opcionesSegundoCarnet, setOpcionesSegundoCarnet] = useState([]);
  const [segundoColaborador, setSegundoColaborador] = useState(null);
  const [segundaFotoUrl, setSegundaFotoUrl] = useState(null);
  const [cargandoSegundo, setCargandoSegundo] = useState(false);
  const segundoCarnetRef = useRef(null);

  // Reinicia la foto cuando cambia `colaborador` (se abre para otro, o se
  // cierra) ajustándolo durante el render en vez de en un efecto — el
  // patrón que React recomienda para esto.
  if (colaborador !== colaboradorPrevio) {
    setColaboradorPrevio(colaborador);
    setFotoUrl(null);
    setCara('frente');
    setModoPvc('uno');
    setSegundoColaborador(null);
    setSegundaFotoUrl(null);
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

  useEffect(() => () => { if (segundaFotoUrl) URL.revokeObjectURL(segundaFotoUrl); }, [segundaFotoUrl]);

  useEffect(() => {
    if (!colaborador || modoPvc !== 'dos') return undefined;
    let cancelado = false;
    fetchColaboradores(1, 100, '').then((items) => {
      if (!cancelado) setOpcionesSegundoCarnet(items.filter((item) => item.id !== colaborador.id));
    }).catch(() => {
      if (!cancelado) message.error('No se pudo cargar la lista de colaboradores.');
    });
    return () => { cancelado = true; };
  }, [colaborador, fetchColaboradores, message, modoPvc]);

  const seleccionarSegundoColaborador = async (colaboradorId) => {
    setCargandoSegundo(true);
    setSegundoColaborador(null);
    if (segundaFotoUrl) URL.revokeObjectURL(segundaFotoUrl);
    setSegundaFotoUrl(null);
    try {
      const [detalle, foto] = await Promise.all([
        fetchColaborador(colaboradorId),
        fetchFotoPerfil(colaboradorId),
      ]);
      setSegundoColaborador(detalle);
      if (foto) setSegundaFotoUrl(URL.createObjectURL(foto));
    } catch {
      message.error('No se pudo cargar el segundo carnet.');
    } finally {
      setCargandoSegundo(false);
    }
  };

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

  /**
   * Rasteriza el carnet a PNG — compartido entre "Descargar PNG" e
   * "Imprimir en PVC" (Agento Print Service), ambos necesitan exactamente
   * el mismo bitmap, solo cambia el destino final (descarga vs. POST al
   * servicio local). pixelRatio:3 da ~1620px de ancho para una tarjeta de
   * 54mm (~762 DPI), de sobra para que el código de barras salga nítido.
   */
  const generarPngCarnet = async (contenedorProp = null) => {
    const contenedor = contenedorProp ?? document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return null;

    // El código de barras real (JsBarcode, decenas de <rect> nativos) es un
    // <svg> anidado dentro del árbol — html-to-image clona todo dentro de
    // un <foreignObject> de un SVG "contenedor", y ese <svg> del barcode
    // ANIDADO adentro rompe la serialización de lo que sigue después en el
    // DOM (texto/ondas del footer), saliendo cortado, aunque en pantalla y
    // al imprimir se vea completo. El arreglo: excluir ese nodo del
    // clonado (`filter`) y sustituirlo por la misma imagen como
    // `background-image` (una simple URL de CSS, sin anidar SVGs) en su
    // contenedor — invisible en pantalla porque el <svg> real queda
    // encima, pero es lo único que html-to-image termina "viendo".
    const svgBarcode = contenedor.querySelector('svg[role="img"]');
    const contenedorBarcode = svgBarcode?.parentElement;
    const fondoPrevio = contenedorBarcode?.style.backgroundImage ?? '';

    if (svgBarcode && contenedorBarcode) {
      const svgTexto = new XMLSerializer().serializeToString(svgBarcode);
      const dataUri = `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(svgTexto)))}`;
      contenedorBarcode.style.backgroundImage = `url("${dataUri}")`;
      contenedorBarcode.style.backgroundRepeat = 'no-repeat';
      contenedorBarcode.style.backgroundPosition = 'center';
    }

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
      return await toPng(contenedor, {
        pixelRatio: 3,
        width: contenedor.offsetWidth,
        height: contenedor.offsetHeight,
        filter: (nodo) => nodo !== svgBarcode,
      });
    } finally {
      contenedor.style.overflow = overflowPrevio;
      if (contenedorBarcode) contenedorBarcode.style.backgroundImage = fondoPrevio;
      if (imgFoto && fotoSrcPrevio) imgFoto.src = fotoSrcPrevio;
    }
  };

  const descargarPng = async () => {
    try {
      const dataUrl = await generarPngCarnet();
      if (!dataUrl) return;
      const enlace = document.createElement('a');
      enlace.href = dataUrl;
      enlace.download = `carnet-${colaborador.nombre_completo}${cara === 'reverso' ? '-reverso' : ''}.png`;
      enlace.click();
    } catch {
      message.error('No se pudo generar la imagen del carnet.');
    }
  };

  const imprimirEnPvcCarnet = async () => {
    setImprimiendoPvc(true);
    try {
      const dataUrl = await generarPngCarnet();
      if (!dataUrl) return;
      if (modoPvc === 'dos') {
        const contenedorSegundo = segundoCarnetRef.current?.querySelector('#carnet-colaborador-imprimible');
        if (!segundoColaborador || !contenedorSegundo) {
          message.warning('Selecciona el segundo colaborador.');
          return;
        }
        const segundoDataUrl = await generarPngCarnet(contenedorSegundo);
        if (!segundoDataUrl) return;
        await imprimirDosEnPvc([
          { imagenBase64: dataUrl, colaboradorId: colaborador.id, nombreMostrable: colaborador.nombre_completo, cara, ranura: 1 },
          { imagenBase64: segundoDataUrl, colaboradorId: segundoColaborador.id, nombreMostrable: segundoColaborador.nombre_completo, cara, ranura: 2 },
        ]);
        message.success('Los 2 carnets se enviaron en un solo trabajo.');
      } else {
        await imprimirEnPvc(dataUrl, { colaboradorId: colaborador.id, nombreMostrable: colaborador.nombre_completo, cara });
        message.success('Carnet enviado a la impresora.');
      }
    } catch (error) {
      message.error(error.message ?? 'No se pudo imprimir el carnet.');
    } finally {
      setImprimiendoPvc(false);
    }
  };

  // resolverPlantillaCarnet() solo elige entre componentes ya definidos a
  // nivel de módulo (PLANTILLAS_POR_EMPRESA/PLANTILLA_GENERICA) — la
  // referencia es estable entre renders aunque el lint no pueda verlo a
  // través de la llamada a función.
  const CarnetTemplate = resolverPlantillaCarnet(colaborador?.empresa?.plantilla_carnet);
  const esPlantillaAncha = PLANTILLAS_ANCHAS.has(CarnetTemplate);
  const SegundoCarnetTemplate = resolverPlantillaCarnet(segundoColaborador?.empresa?.plantilla_carnet);
  const esSegundaPlantillaAncha = PLANTILLAS_ANCHAS.has(SegundoCarnetTemplate);

  return (
    <Modal
      title="Carnet de colaborador"
      open={Boolean(colaborador)}
      onCancel={onClose}
      footer={[
        <Button key="cerrar" onClick={onClose}>Cerrar</Button>,
        <Tooltip key="descargar" title="Descargar PNG">
          <Button icon={<DownloadOutlined />} onClick={descargarPng} />
        </Tooltip>,
        <Tooltip key="imprimir" title="Imprimir">
          <Button icon={<PrinterOutlined />} onClick={imprimirCarnet} />
        </Tooltip>,
        // Solo aparece si Agento Print Service responde en esta PC (ver
        // usePrintServiceLocal) — imprime directo a la bandeja de tarjeta
        // ID de la Epson L8050, sin pasar por Epson Photo+ ni por el
        // diálogo de impresión del navegador. Si el servicio no está
        // instalado/corriendo en esta máquina, este botón simplemente no
        // se muestra y el resto del carnet sigue funcionando igual.
        printServiceDisponible && (
          <Button
            key="imprimir-pvc"
            type="primary"
            icon={<UsbOutlined />}
            loading={imprimiendoPvc}
            disabled={modoPvc === 'dos' && (!segundoColaborador || cargandoSegundo)}
            onClick={imprimirEnPvcCarnet}
          >
            {modoPvc === 'dos' ? 'Imprimir 2 en PVC' : 'Imprimir en PVC'}
          </Button>
        ),
      ].filter(Boolean)}
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
          <div className="w-full space-y-3 rounded-lg border border-purple-200 bg-purple-50 p-3">
            <Typography.Text strong>Tarjetas en la bandeja</Typography.Text>
            <div>
              <Segmented
                block
                options={[
                  { label: '1 ranura', value: 'uno' },
                  { label: '2 ranuras', value: 'dos' },
                ]}
                value={modoPvc}
                onChange={setModoPvc}
              />
            </div>
              {modoPvc === 'dos' && (
                <div className="grid grid-cols-2 gap-2">
                  <div className="rounded-md border border-purple-200 bg-white p-2">
                    <Typography.Text type="secondary">Ranura 1</Typography.Text>
                    <div className="truncate font-medium" title={colaborador.nombre_completo}>
                      {colaborador.nombre_completo}
                    </div>
                  </div>
                  <div className="rounded-md border border-purple-200 bg-white p-2">
                    <Typography.Text type="secondary">Ranura 2</Typography.Text>
                  <Select
                    className="w-full"
                    showSearch
                    optionFilterProp="label"
                    loading={cargandoSegundo}
                    placeholder="Seleccionar carnet"
                    value={segundoColaborador?.id}
                    onChange={seleccionarSegundoColaborador}
                    options={opcionesSegundoCarnet.map((item) => ({
                      value: item.id,
                      label: `${item.nombre_completo} · ${item.documento ?? item.numero_documento ?? ''}`,
                    }))}
                  />
                  </div>
                </div>
              )}
            {!printServiceDisponible && (
              <Typography.Text type="secondary">Inicia el servicio local para habilitar la impresión PVC.</Typography.Text>
            )}
          </div>
          {modoPvc === 'dos' ? (
            <div className="grid w-full grid-cols-2 justify-items-center gap-3">
              <VistaCarnetRanura
                numero={1}
                Template={CarnetTemplate}
                colaborador={colaborador}
                fotoUrl={fotoUrl}
                cara={cara}
                esPlantillaAncha={esPlantillaAncha}
              />
              {segundoColaborador ? (
                <VistaCarnetRanura
                  numero={2}
                  Template={SegundoCarnetTemplate}
                  colaborador={segundoColaborador}
                  fotoUrl={segundaFotoUrl}
                  cara={cara}
                  esPlantillaAncha={esSegundaPlantillaAncha}
                  contenedorRef={segundoCarnetRef}
                />
              ) : (
                <div className="flex h-full min-h-64 w-[150px] items-center justify-center rounded-md border border-dashed border-purple-300 bg-purple-50 px-3 text-center text-gray-500">
                  Selecciona el colaborador de la ranura 2
                </div>
              )}
            </div>
          ) : cara === 'frente' ? (
            esPlantillaAncha ? (
              <div style={{ width: ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA, height: ALTO_NATIVO_PLANTILLA_ANCHA * ESCALA_VISTA_PREVIA }}>
                <div style={{ transform: `scale(${ESCALA_VISTA_PREVIA})`, transformOrigin: 'top left' }}>
                  {/* eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet() */}
                  <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} />
                </div>
              </div>
            ) : (
              // eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet()
              <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} />
            )
          ) : (
            <CarnetColaboradorReverso colaborador={colaborador} />
          )}
        </div>
      )}
    </Modal>
  );
}
