import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal } from 'antd';
import { useEffect, useState } from 'react';
import BoletaDocumento from './BoletaDocumento';
import EstilosImpresionBoleta from './EstilosImpresionBoleta';

/**
 * Vista imprimible de una boleta YA calculada — renderiza el snapshot tal
 * cual quedó guardado, nunca recalcula nada. "Imprimir" usa window.print()
 * (ver EstilosImpresionBoleta) para que el usuario elija "Guardar como PDF"
 * desde el diálogo del navegador — evita agregar una librería de generación
 * de PDF en el backend solo para esto.
 */
export default function BoletaImprimibleModal({ open, onCancel, boletaId, verBoleta }) {
  const [detalle, setDetalle] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!open || !boletaId) return;
    let activo = true;
    setLoading(true);
    verBoleta(boletaId).then((data) => { if (activo) setDetalle(data); }).finally(() => activo && setLoading(false));
    return () => { activo = false; };
  }, [open, boletaId, verBoleta]);

  const esOficial = detalle?.estado === 'pagada';

  return (
    <Modal
      title={esOficial ? 'Boleta oficial' : 'Previsualización de boleta'}
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
          {esOficial ? 'Descargar boleta oficial' : 'Imprimir vista previa'}
        </Button>,
      ]}
      width={{ xs: '95%', sm: '92%', md: 820 }}
      destroyOnHidden
    >
      {loading || !detalle ? (
        <p className="p-6 text-center text-sm text-gray-400">Cargando boleta...</p>
      ) : (
        <div id="boleta-imprimible">
          <BoletaDocumento detalle={detalle} />
        </div>
      )}

      <EstilosImpresionBoleta targetId="boleta-imprimible" />
    </Modal>
  );
}
