import { PrinterOutlined } from '@ant-design/icons';
import { App, Button, Modal } from 'antd';
import { useEffect, useState } from 'react';
import BoletaDocumento from './BoletaDocumento';
import EstilosImpresionBoleta from './EstilosImpresionBoleta';

/**
 * Igual que BoletaImprimibleModal pero para varias boletas a la vez —
 * comparte BoletaDocumento (mismo layout, boleta por boleta) y
 * EstilosImpresionBoleta (mismo mecanismo window.print(), con salto de
 * página entre boletas vía .boleta-salto-pagina).
 */
export default function BoletasImprimirMasivoModal({ open, onCancel, cicloId, boletaIds, imprimirBoletasMasivo }) {
  const { message } = App.useApp();
  const [detalles, setDetalles] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!open || !cicloId || !boletaIds.length) return;
    let activo = true;
    setLoading(true);
    imprimirBoletasMasivo(cicloId, boletaIds)
      .then((data) => { if (activo) setDetalles(data); })
      .catch(() => { if (activo) message.error('No se pudieron cargar las boletas seleccionadas.'); })
      .finally(() => activo && setLoading(false));
    return () => { activo = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, cicloId, boletaIds, imprimirBoletasMasivo]);

  return (
    <Modal
      title={`Imprimir ${boletaIds.length} boleta(s)`}
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} disabled={loading || !detalles.length} onClick={() => window.print()}>
          Imprimir {detalles.length} boleta(s)
        </Button>,
      ]}
      width={{ xs: '95%', sm: '92%', md: 820 }}
      destroyOnHidden
    >
      {loading ? (
        <p className="p-6 text-center text-sm text-gray-400">Cargando boletas...</p>
      ) : !detalles.length ? (
        <p className="p-6 text-center text-sm text-gray-400">No se encontraron boletas para imprimir.</p>
      ) : (
        <div id="boletas-imprimibles-masivo" className="space-y-4 print:space-y-0">
          {detalles.map((detalle) => (
            <div key={detalle.id} className="boleta-salto-pagina">
              <BoletaDocumento detalle={detalle} />
            </div>
          ))}
        </div>
      )}

      <EstilosImpresionBoleta targetId="boletas-imprimibles-masivo" />
    </Modal>
  );
}
