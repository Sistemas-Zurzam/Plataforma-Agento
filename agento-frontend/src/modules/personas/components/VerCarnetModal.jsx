import { PrinterOutlined } from '@ant-design/icons';
import { App, Button, Modal } from 'antd';
import { useEffect, useState } from 'react';
import { useColaboradores } from '../hooks/useColaboradores';
import CarnetColaborador from './CarnetColaborador';

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

  useEffect(() => {
    setFotoUrl(null);
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

  /**
   * Clona TODOS los <style>/<link rel="stylesheet"> del documento actual
   * hacia una ventana nueva — así el carnet impreso mantiene exactamente
   * los mismos estilos (Tailwind incluido) sin depender de adivinar rutas
   * de build. @page fuerza el tamaño físico real de una tarjeta CR-80 en
   * vertical (53.98 × 85.6 mm) para que salga bien alineado en bandejas
   * de PVC.
   */
  const imprimirCarnet = () => {
    const contenedor = document.getElementById('carnet-colaborador-imprimible');
    if (!contenedor) return;

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
          <title>Carnet — ${colaborador.nombre_completo}</title>
          ${estilos}
          <style>
            @page { size: 53.98mm 85.6mm; margin: 0; }
            html, body { margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; }
            #carnet-colaborador-imprimible { width: 53.98mm !important; height: 85.6mm !important; box-shadow: none !important; border-radius: 0 !important; }
          </style>
        </head>
        <body>${contenedor.outerHTML}</body>
      </html>`);
    ventana.document.close();
    ventana.onload = () => {
      ventana.focus();
      ventana.print();
    };
  };

  return (
    <Modal
      title="Carnet de colaborador"
      open={Boolean(colaborador)}
      onCancel={onClose}
      footer={[
        <Button key="cerrar" onClick={onClose}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={imprimirCarnet}>Imprimir</Button>,
      ]}
      centered
    >
      {colaborador && (
        <div className="flex justify-center py-2">
          <CarnetColaborador colaborador={colaborador} fotoUrl={fotoUrl} />
        </div>
      )}
    </Modal>
  );
}
