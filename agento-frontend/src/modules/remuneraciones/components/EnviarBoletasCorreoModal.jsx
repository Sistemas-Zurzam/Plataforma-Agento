import { MailOutlined } from '@ant-design/icons';
import { Alert, App, Button, Checkbox, Empty, Modal, Spin, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';

const TEXTOS_BOLETA = {
  titulo: 'Enviar boletas por correo',
  aviso: 'Se envía la boleta de pago oficial',
  detalle: 'Cada colaborador recibe su boleta pagada en PDF al correo registrado en su ficha. Antes de enviar, verifica que las boletas estén correctas.',
  documento: 'boleta(s)',
};

/**
 * Confirmación del envío por correo (boleta de pago o boleta de CTS): antes de
 * enviar muestra a quién le llegará (con su correo de la ficha) y a quién no,
 * con el motivo. El backend vuelve a clasificar al enviar; este resumen es solo
 * para que RR.HH. decida. `origenId` es el ciclo o la CTS; `ids` las filas
 * elegidas; `idKey` el campo que las identifica en la respuesta.
 */
export default function EnviarBoletasCorreoModal({
  open, onCancel, onEnviado, origenId, ids, previsualizar, enviarCorreo, idKey = 'boleta_id', textos = TEXTOS_BOLETA,
}) {
  const { message } = App.useApp();
  // null mientras se prepara el resumen.
  const [resumen, setResumen] = useState(null);
  const [enviando, setEnviando] = useState(false);
  // Por defecto no se reenvía a quien ya recibió su boleta.
  const [reenviar, setReenviar] = useState(false);

  useEffect(() => {
    if (!open || !origenId || ids.length === 0) return undefined;
    let activo = true;
    previsualizar(origenId, ids, reenviar)
      .then((data) => { if (activo) setResumen(data); })
      .catch(() => {
        if (!activo) return;
        setResumen({ enviables: [], omitidas: [] });
        message.error('No se pudo preparar el envío');
      });
    return () => { activo = false; };
  }, [open, origenId, ids, reenviar, previsualizar, message]);

  const cerrar = () => {
    setResumen(null);
    setReenviar(false);
    onCancel();
  };

  const enviables = resumen?.enviables ?? [];
  const omitidas = resumen?.omitidas ?? [];
  const yaRecibidas = omitidas.filter((fila) => fila.ya_recibida).length;

  const cambiarReenviar = (valor) => {
    setResumen(null);
    setReenviar(valor);
  };

  const enviar = async () => {
    setEnviando(true);
    try {
      const resultado = await enviarCorreo(origenId, enviables.map((fila) => fila[idKey]), reenviar);
      message.success(`${resultado.encolados} ${textos.documento} en envío. El estado se actualiza en la columna "Correo".`);
      if (resultado.en_cola > 0) message.info(`${resultado.en_cola} ya estaban en cola y no se duplicaron.`);
      setResumen(null);
      setReenviar(false);
      onEnviado();
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo enviar por correo');
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal
      open={open}
      onCancel={cerrar}
      title={textos.titulo}
      width={760}
      destroyOnHidden
      footer={[
        <Button key="cancelar" onClick={cerrar}>Cancelar</Button>,
        <Button key="enviar" type="primary" icon={<MailOutlined />} loading={enviando} disabled={!resumen || enviables.length === 0} onClick={enviar}>
          Enviar {enviables.length} {textos.documento}
        </Button>,
      ]}
    >
      <Spin spinning={!resumen}>
        <div className="space-y-4">
          <Alert
            type="info"
            showIcon
            message={textos.aviso}
            description={textos.detalle}
          />
          {(yaRecibidas > 0 || reenviar) && (
            <Checkbox checked={reenviar} onChange={(e) => cambiarReenviar(e.target.checked)}>
              Reenviar también a quienes ya la recibieron{!reenviar && ` (${yaRecibidas})`}
              <span className="block text-xs text-gray-500">Úsalo solo si alguien no encuentra su correo.</span>
            </Checkbox>
          )}

          <div>
            <p className="mb-2 font-semibold text-gray-900">Se enviará a ({enviables.length})</p>
            <Table
              size="small"
              rowKey={idKey}
              dataSource={enviables}
              pagination={enviables.length > 8 ? { pageSize: 8, size: 'small' } : false}
              locale={{ emptyText: <Empty description="Ninguna boleta seleccionada se puede enviar" /> }}
              columns={[
                { title: 'Colaborador', dataIndex: 'colaborador' },
                { title: 'Correo', dataIndex: 'email' },
                {
                  title: 'Envío anterior',
                  key: 'anterior',
                  width: 170,
                  render: (_, fila) => (fila.ultimo_envio
                    ? <Tag color="orange">{fila.ultimo_envio.enviado_at ? dayjs(fila.ultimo_envio.enviado_at).format('DD/MM HH:mm') : fila.ultimo_envio.estado}</Tag>
                    : <span className="text-gray-400">—</span>),
                },
              ]}
            />
          </div>

          {omitidas.length > 0 && (
            <div>
              <p className="mb-2 font-semibold text-gray-900">No se enviará a ({omitidas.length})</p>
              <Table
                size="small"
                rowKey={idKey}
                dataSource={omitidas}
                pagination={omitidas.length > 8 ? { pageSize: 8, size: 'small' } : false}
                columns={[
                  { title: 'Colaborador', dataIndex: 'colaborador' },
                  { title: 'Motivo', dataIndex: 'motivo', render: (motivo) => <span className="text-orange-600">{motivo}</span> },
                ]}
              />
              <p className="mt-1 text-xs text-gray-500">Si falta el correo, complétalo en la ficha del colaborador y vuelve a enviar.</p>
            </div>
          )}
        </div>
      </Spin>
    </Modal>
  );
}
