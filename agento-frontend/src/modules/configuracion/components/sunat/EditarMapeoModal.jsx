import { Form, Input, Modal, Switch } from 'antd';
import { useEffect } from 'react';

/**
 * Modal compartido por los 4 catálogos "genéricos" (tipo de documento, tipo
 * de trabajador, régimen laboral, tipo de comprobante RH) — todos tienen
 * exactamente la misma forma (clave interna fija + equivalencia SUNAT), así
 * que reutilizan el mismo formulario en vez de 4 modales casi idénticos.
 * El valor interno de Agento (clave_interna) NUNCA se edita acá.
 */
export default function EditarMapeoModal({ open, mapeo, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (!open || !mapeo) return;
    form.setFieldsValue({
      codigo_sunat: mapeo.codigo_sunat ?? '',
      descripcion_sunat: mapeo.descripcion_sunat ?? '',
      activo: mapeo.activo ?? true,
    });
  }, [open, mapeo, form]);

  const guardar = (values) => {
    onGuardar(mapeo.id, {
      ...values,
      codigo_sunat: values.codigo_sunat?.trim() || null,
      descripcion_sunat: values.descripcion_sunat?.trim() || null,
    });
  };

  return (
    <Modal
      title="Editar mapeo SUNAT"
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      okText="Guardar"
      cancelText="Cancelar"
      confirmLoading={submitting}
      destroyOnHidden
    >
      <p className="mb-4 text-sm text-gray-500">
        Valor interno de Agento: <code className="rounded bg-gray-100 px-1.5 py-0.5">{mapeo?.clave_interna}</code>{' '}
        (no editable — solo se configura su equivalencia SUNAT).
      </p>
      <Form form={form} layout="vertical" onFinish={guardar}>
        <Form.Item label="Código SUNAT" name="codigo_sunat">
          <Input placeholder="Sin configurar" maxLength={20} />
        </Form.Item>
        <Form.Item label="Descripción SUNAT" name="descripcion_sunat">
          <Input placeholder="Descripción oficial del código (opcional)" maxLength={255} />
        </Form.Item>
        <Form.Item label="Activo" name="activo" valuePropName="checked">
          <Switch />
        </Form.Item>
      </Form>
    </Modal>
  );
}
