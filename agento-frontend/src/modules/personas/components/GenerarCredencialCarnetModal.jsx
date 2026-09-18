import { PrinterOutlined, ReloadOutlined, SafetyCertificateOutlined, StopOutlined } from '@ant-design/icons';
import { App, Button, Modal } from 'antd';
import { useEffect, useState } from 'react';
import api from '../../../services/api';
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
  const { message, modal } = App.useApp();
  const [estado, setEstado] = useState(null); // { tiene_credencial_activa, generado_at }
  const [token, setToken] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [colaboradorPrevio, setColaboradorPrevio] = useState(colaborador);

  // Reinicia el estado del modal cuando cambia `colaborador` (se abre para
  // otro colaborador, o se cierra) ajustándolo durante el render en vez de
  // en un efecto — el patrón que React recomienda para esto, evita el
  // reset-y-luego-fetch en cascada de un useEffect.
  if (colaborador !== colaboradorPrevio) {
    setColaboradorPrevio(colaborador);
    setToken(null);
    setEstado(null);
  }

  useEffect(() => {
    if (!colaborador) return;

    api.get(`/colaboradores/${colaborador.id}/credencial-carnet`).then(({ data }) => setEstado(data.data));
  }, [colaborador]);

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
                <CarnetTemplate colaborador={colaborador} credencialToken={token} />
              </div>
            </div>
          ) : (
            // eslint-disable-next-line react-hooks/static-components -- ver comentario junto a resolverPlantillaCarnet()
            <CarnetTemplate colaborador={colaborador} credencialToken={token} />
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
