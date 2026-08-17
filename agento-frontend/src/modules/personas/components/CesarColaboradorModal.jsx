import { DatePicker, Form, Input, Modal } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

export default function CesarColaboradorModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  useEffect(() => {
    if (open) form.setFieldsValue({ fecha_cese: dayjs(), motivo_cese: null });
  }, [open, form]);

  return <Modal title="Cesar colaborador" open={open} onCancel={onCancel} onOk={() => form.submit()} okText="Confirmar cese" okButtonProps={{ danger: true }} confirmLoading={submitting} centered destroyOnHidden>
    <Form form={form} layout="vertical" onFinish={(values) => onGuardar({ ...values, fecha_cese: values.fecha_cese.format('YYYY-MM-DD') })}>
      <Form.Item label="Fecha de cese" name="fecha_cese" rules={[{ required: true }]}><DatePicker className="w-full" format="DD/MM/YYYY" minDate={colaborador?.fecha_ingreso ? dayjs(colaborador.fecha_ingreso) : undefined} /></Form.Item>
      <Form.Item label="Motivo del cese" name="motivo_cese" rules={[{ required: true, message: 'Indica el motivo del cese' }]}><Input.TextArea rows={3} maxLength={255} showCount /></Form.Item>
    </Form>
  </Modal>;
}
