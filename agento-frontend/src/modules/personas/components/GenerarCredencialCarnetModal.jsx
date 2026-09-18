import { DownloadOutlined, PrinterOutlined, ReloadOutlined, SafetyCertificateOutlined, StopOutlined } from '@ant-design/icons';
import { App, Button, Modal } from 'antd';
import { toPng } from 'html-to-image';
import { useEffect, useState } from 'react';
import api from '../../../services/api';
import { useColaboradores } from '../hooks/useColaboradores';
import {
  ALTO_NATIVO_PLANTILLA_ANCHA,
  ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA,
  ESCALA_VISTA_PREVIA,
  PLANTILLAS_ANCHAS,
  resolverPlantillaCarnet,
} from './plantillasCarnet';

/**
 * Genera/regenera/revoca la credencial de carnet (código de barras) del
 * colaborador. El token PLANO solo existe en la respuesta de esta llamada
 * — el backend nunca vuelve a devolverlo (solo guarda su hash) — por eso
 * este modal, a diferencia de VerCarnetModal, es el único lugar donde el
 * carnet se ve con el código de barras REAL en vez del decorativo.
 */
export default function GenerarCredencialCarnetModal({ colaborador, onClose }) {
  const { fetchFotoPerfil } = useColaboradores();
  const { message, modal } = App.useApp();
  const [estado, setEstado] = useState(null); // { tiene_credencial_activa, generado_at }
  const [token, setToken] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [fotoUrl, setFotoUrl] = useState(null);
  const [colaboradorPrevio, setColaboradorPrevio] = useState(colaborador);

  // Reinicia el estado del modal cuando cambia `colaborador` (se abre para
  // otro colaborador, o se cierra) ajustándolo durante el render en vez de
  // en un efecto — el patrón que React recomienda para esto, evita el
  // reset-y-luego-fetch en cascada de un useEffect.
  if (colaborador !== colaboradorPrevio) {
    setColaboradorPrevio(colaborador);
    setToken(null);
    setEstado(null);
    setFotoUrl(null);
  }

  useEffect(() => {
    if (!colaborador) return;

    api.get(`/colaboradores/${colaborador.id}/credencial-carnet`).then(({ data }) => setEstado(data.data));
  }, [colaborador]);

  // Sin esto el carnet se veía siempre con el avatar de iniciales, incluso
  // con foto de perfil ya subida — a diferencia de VerCarnetModal, este
  // modal nunca pedía la foto.
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

  const generar = async (regenerando) => {
    setCargando(true);
    try {
      const { data } = await api.post(
        `/colaboradores/${colaborador.id}/credencial-carnet${regenerando ? '/regenerar' : ''}`,
      );
      setToken(data.data.token);
      setEstado({ tiene_credencial_activa: true, generado_at: data.data.generado_at });
    } catch {
      message.error('No se pudo generar la credencial.');
    } finally {
      setCargando(false);
    }
  };

  const revocar = () => {
    modal.confirm({
      title: 'Revocar credencial de carnet',
      content: 'El carnet impreso dejará de funcionar para marcar asistencia de inmediato. Esta acción no se puede deshacer.',
      okText: 'Revocar',
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await api.delete(`/colaboradores/${colaborador.id}/credencial-carnet`, { data: { motivo: 'Revocada desde ficha de colaborador' } });
          setEstado({ tiene_credencial_activa: false, generado_at: null });
          setToken(null);
          message.success('Credencial revocada.');
        } catch {
          message.error('No se pudo revocar la credencial.');
        }
      },
    });
  };

  const imprimir = () => {
    const contenedor = document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return;
    const anchoRenderizado = contenedor.offsetWidth;

    const ventana = window.open('', '_blank', 'width=380,height=640');
    if (!ventana) {
      message.error('El navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes para este sitio.');
      return;
    }

    const estilos = Array.from(document.querySelectorAll('style, link[rel="stylesheet"]')).map((el) => el.outerHTML).join('\n');

    ventana.document.write(`<!doctype html>
      <html>
        <head>
          <title>Carnet — ${colaborador.nombre_completo}</title>
          ${estilos}
          <style>
            @page { size: 54mm 86mm; margin: 0; }
            html, body { margin: 0; padding: 0; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            #carnet-imprimible-pagina { width: 54mm; height: 86mm; overflow: hidden; }
            #carnet-colaborador-imprimible { transform: scale(calc(54mm / ${anchoRenderizado}px)); transform-origin: top left; box-shadow: none !important; border-radius: 0 !important; }
          </style>
        </head>
        <body><div id="carnet-imprimible-pagina">${contenedor.outerHTML}</div></body>
      </html>`);
    ventana.document.close();
    ventana.onload = () => {
      ventana.focus();
      ventana.print();
    };
  };

  // La impresora física (Epson L8050, tarjetas PVC) se imprime desde el
  // software Epson Photo+, no desde el diálogo de impresión del navegador
  // — por eso hace falta poder descargar el carnet como imagen en vez de
  // depender únicamente de `imprimir()`. pixelRatio:3 da ~1620px de ancho
  // (más que suficiente para el tamaño físico de 54mm), sin generar un
  // archivo desproporcionadamente pesado.
  //
  // Este modal es el ÚNICO lugar donde se renderiza el <svg> REAL del
  // código de barras (JsBarcode, decenas de <rect> nativos) — en
  // VerCarnetModal el mismo espacio siempre muestra el div decorativo, sin
  // token. html-to-image clona todo el árbol dentro de un <foreignObject>
  // de un SVG "contenedor"; ese <svg> del barcode ANIDADO dentro rompe la
  // serialización de lo que sigue después en el DOM (el texto y las ondas
  // del footer, que van justo debajo) y el resultado sale cortado ahí,
  // aunque en pantalla y al imprimir se vea completo. El arreglo: excluir
  // ese nodo del clonado (`filter`) y sustituirlo por la misma imagen como
  // `background-image` (una simple URL de CSS, sin anidar SVGs) en su
  // contenedor — invisible en pantalla porque el <svg> real queda encima,
  // pero es lo único que html-to-image termina "viendo".
  const descargarPng = async () => {
    const contenedor = document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return;

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

    // La foto de perfil se pasa como URL `blob:` (ver fetchFotoPerfil más
    // arriba) — html-to-image, al clonar el árbol, vuelve a pedir por red
    // cada <img> para incrustarlo como data-URI, y para ese momento el
    // blob ya no está disponible (`net::ERR_FILE_NOT_FOUND`), aunque en
    // pantalla el <img> siga mostrándola con normalidad porque el
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
        filter: (nodo) => nodo !== svgBarcode,
      });
      const enlace = document.createElement('a');
      enlace.href = dataUrl;
      enlace.download = `carnet-${colaborador.nombre_completo}.png`;
      enlace.click();
    } catch {
      message.error('No se pudo generar la imagen del carnet.');
    } finally {
      contenedor.style.overflow = overflowPrevio;
      if (contenedorBarcode) contenedorBarcode.style.backgroundImage = fondoPrevio;
      if (imgFoto && fotoSrcPrevio) imgFoto.src = fotoSrcPrevio;
    }
  };

  if (!colaborador) return null;

  // resolverPlantillaCarnet() solo elige entre componentes ya definidos a
  // nivel de módulo (PLANTILLAS_POR_EMPRESA/PLANTILLA_GENERICA) — la
  // referencia es estable entre renders aunque el lint no pueda verlo a
  // través de la llamada a función.
  const CarnetTemplate = resolverPlantillaCarnet(colaborador.empresa?.plantilla_carnet);
  const esPlantillaAncha = PLANTILLAS_ANCHAS.has(CarnetTemplate);

  return (
    <Modal
      title="Código de acceso (carnet)"
      open={Boolean(colaborador)}
      onCancel={onClose}
      width={460}
      centered
      footer={[
        <Button key="cerrar" onClick={onClose}>Cerrar</Button>,
        estado?.tiene_credencial_activa && (
          <Button key="revocar" danger icon={<StopOutlined />} onClick={revocar}>Revocar</Button>
        ),
        <Button
          key="generar"
          type="primary"
          icon={estado?.tiene_credencial_activa ? <ReloadOutlined /> : <SafetyCertificateOutlined />}
          loading={cargando}
          onClick={() => generar(estado?.tiene_credencial_activa)}
        >
          {estado?.tiene_credencial_activa ? 'Regenerar código' : 'Generar código'}
        </Button>,
        token && <Button key="descargar" icon={<DownloadOutlined />} onClick={descargarPng}>Descargar PNG</Button>,
        token && <Button key="imprimir" icon={<PrinterOutlined />} onClick={imprimir}>Imprimir</Button>,
      ].filter(Boolean)}
    >
      {token ? (
        <div className="flex flex-col items-center gap-3 py-2">
          <p className="rounded-md bg-amber-50 px-3 py-2 text-center text-sm text-amber-700">
            Este código no se puede volver a mostrar. Imprime o guarda el carnet ahora — si lo pierdes, deberás
            generar uno nuevo (el anterior dejará de funcionar).
          </p>
          {esPlantillaAncha ? (
            <div style={{ width: ANCHO_VISTA_PREVIA_PLANTILLA_ANCHA, height: ALTO_NATIVO_PLANTILLA_ANCHA * ESCALA_VISTA_PREVIA }}>
              <div style={{ transform: `scale(${ESCALA_VISTA_PREVIA})`, transformOrigin: 'top left' }}>
                {/* eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet() */}
                <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} credencialToken={token} />
              </div>
            </div>
          ) : (
            // eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet()
            <CarnetTemplate colaborador={colaborador} fotoUrl={fotoUrl} credencialToken={token} />
          )}
        </div>
      ) : (
        <div className="py-6 text-center text-gray-500">
          {estado?.tiene_credencial_activa ? (
            <p>Este colaborador ya tiene una credencial activa. Genera un código nuevo solo si necesita reimprimir el carnet o el anterior se perdió (invalidará el actual).</p>
          ) : (
            <p>Este colaborador todavía no tiene una credencial de carnet para marcar asistencia por código de barras.</p>
          )}
        </div>
      )}
    </Modal>
  );
}
