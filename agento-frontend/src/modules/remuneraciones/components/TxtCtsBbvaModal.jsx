import { DownloadOutlined } from '@ant-design/icons';
import { Alert, App, Button, Modal, Spin, Table } from 'antd';
import { useEffect, useState } from 'react';

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * TXT de depósito de CTS para BBVA (formato de la macro "CTS ONLINE"). Antes de
 * descargar muestra quiénes entran (con el saldo por depositar), quiénes no y
 * por qué, el total y la cuenta de cargo. Descargar no registra el depósito.
 */
export default function TxtCtsBbvaModal({ open, onCancel, beneficioId, detalleIds, validarTxtCtsBbva, exportarTxtCtsBbva }) {
  const { message } = App.useApp();
  // null mientras se valida.
  const [resumen, setResumen] = useState(null);
  const [descargando, setDescargando] = useState(false);

  useEffect(() => {
    if (!open || !beneficioId || detalleIds.length === 0) return undefined;
    let activo = true;
    validarTxtCtsBbva(beneficioId, detalleIds)
      .then((data) => { if (activo) setResumen(data); })
      .catch(() => {
        if (!activo) return;
        setResumen({ incluidos: [], omitidos: [], total: 0, error: 'No se pudo validar el TXT.' });
      });
    return () => { activo = false; };
  }, [open, beneficioId, detalleIds, validarTxtCtsBbva]);

  const cerrar = () => {
    setResumen(null);
    onCancel();
  };

  const descargar = async () => {
    setDescargando(true);
    try {
      await exportarTxtCtsBbva(beneficioId, detalleIds);
      message.success('TXT de CTS generado. Cuando BBVA procese el abono, registra el depósito con el N.º de operación.');
      cerrar();
    } catch {
      message.error('No se pudo generar el TXT de CTS');
    } finally {
      setDescargando(false);
    }
  };

  return (
    <Modal
      open={open}
      onCancel={cerrar}
      title="TXT de depósito de CTS — BBVA"
      width={760}
      destroyOnHidden
      footer={[
        <Button key="cancelar" onClick={cerrar}>Cancelar</Button>,
        <Button key="descargar" type="primary" icon={<DownloadOutlined />} loading={descargando}
          disabled={!resumen || Boolean(resumen.error)} onClick={descargar}>
          Descargar TXT ({resumen?.incluidos?.length ?? 0})
        </Button>,
      ]}
    >
      <Spin spinning={!resumen}>
        <div className="space-y-4">
          {resumen?.error ? (
            <Alert type="error" showIcon message={resumen.error} />
          ) : (
            <Alert
              type="info"
              showIcon
              message={`Total a depositar: ${soles(resumen?.total)}`}
              description={resumen?.cuenta_cargo ? `Cuenta de cargo BBVA ${resumen.cuenta_cargo.numero} (${resumen.cuenta_cargo.uso === 'cts' ? 'depósito de CTS' : 'pago de haberes'}). Sube el archivo en BBVA Net Cash y, cuando se procese, registra el depósito con el N.º de operación.` : ''}
            />
          )}

          <div>
            <p className="mb-2 font-semibold text-gray-900">Se incluye en el TXT ({resumen?.incluidos?.length ?? 0})</p>
            <Table
              size="small"
              rowKey="detalle_id"
              dataSource={resumen?.incluidos ?? []}
              pagination={(resumen?.incluidos?.length ?? 0) > 8 ? { pageSize: 8, size: 'small' } : false}
              columns={[
                { title: 'Colaborador', dataIndex: 'colaborador' },
                { title: 'Importe', dataIndex: 'importe', align: 'right', render: soles },
              ]}
            />
          </div>

          {(resumen?.omitidos?.length ?? 0) > 0 && (
            <div>
              <p className="mb-2 font-semibold text-gray-900">No se incluye ({resumen.omitidos.length})</p>
              <Table
                size="small"
                rowKey="detalle_id"
                dataSource={resumen.omitidos}
                pagination={resumen.omitidos.length > 8 ? { pageSize: 8, size: 'small' } : false}
                columns={[
                  { title: 'Colaborador', dataIndex: 'colaborador' },
                  { title: 'Motivo', dataIndex: 'motivo', render: (motivo) => <span className="text-orange-600">{motivo}</span> },
                ]}
              />
              <p className="mt-1 text-xs text-gray-500">Completa la cuenta CTS (BBVA, 20 dígitos) en la ficha del colaborador y vuelve a generar el TXT.</p>
            </div>
          )}
        </div>
      </Spin>
    </Modal>
  );
}
