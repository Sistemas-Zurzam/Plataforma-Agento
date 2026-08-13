import { Form, Input, Modal } from 'antd';
import { useEffect } from 'react';

export default function SedeFormModal({
  open,
  title,
  initialValues,
  onSubmit,
  onCancel,
  submitting,
}) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (open) {
      form.setFieldsValue({
        nombre: initialValues?.nombre ?? '',
        direccion: initialValues?.direccion ?? '',
      });
    }
  }, [open, initialValues, form]);

  return (
    <Modal
      title={title}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText={initialValues ? 'Guardar cambios' : 'Crear'}
      cancelText="Cancelar"
      destroyOnHidden
      width={420}
    >
      <Form form={form} layout="vertical" onFinish={(values) => onSubmit(values, form)}>
        <Form.Item
          label="Nombre de la sede"
          name="nombre"
          rules={[{ required: true, message: 'Ingresa el nombre de la sede' }]}
        >
          <Input placeholder="Ej: Sede San Juan de Lurigancho" />
        </Form.Item>

        <Form.Item label="Dirección (opcional)" name="direccion">
          <Input placeholder="Av. Akapana 1261, Lima" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
