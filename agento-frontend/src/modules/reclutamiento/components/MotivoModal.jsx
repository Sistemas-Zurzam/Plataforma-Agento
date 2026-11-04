import { Alert, Form, Input, Modal } from 'antd';
import { useState } from 'react';

/**
 * Confirmación de acciones sensibles: explica el efecto y, si corresponde,
 * exige un motivo antes de ejecutar.
 */
export default function MotivoModal({
  abierto,
  titulo,
  efecto,
  etiquetaMotivo = 'Motivo',
  motivoObligatorio = true,
  textoConfirmar = 'Confirmar',
  peligroso = false,
  campoExtra = null,
  onConfirmar,
  onCerrar,
}) {
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);

  const confirmar = async () => {
    const valores = await form.validateFields();
    setEnviando(true);
    try {
      await onConfirmar(valores);
      form.resetFields();
    } catch {
      // Quien llama ya mostró el error; el modal queda abierto para corregir.
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal
      open={abierto}
      title={titulo}
      okText={textoConfirmar}
      cancelText="Cancelar"
      okButtonProps={{ danger: peligroso, loading: enviando }}
      onOk={confirmar}
      onCancel={() => {
        form.resetFields();
        onCerrar();
      }}
      destroyOnHidden
    >
      {efecto && <Alert type={peligroso ? 'warning' : 'info'} showIcon message={efecto} className="mb-4" />}
      <Form form={form} layout="vertical">
        {campoExtra}
        <Form.Item
          name="motivo"
          label={etiquetaMotivo}
          rules={motivoObligatorio ? [{ required: true, message: 'Indica el motivo.' }] : []}
        >
          <Input.TextArea rows={3} maxLength={2000} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}
