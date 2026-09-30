import { DatePicker, Form, Modal } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

export default function EditarCorteAsistenciaModal({ ciclo, open, loading, onCancel, onSubmit }) {
  const [form] = Form.useForm();
  useEffect(() => {
    if (open) form.setFieldsValue({ fecha_corte_asistencia: dayjs(ciclo?.fecha_corte_asistencia) });
    else form.resetFields();
  }, [open, ciclo, form]);

  return <Modal title="Cambiar corte de asistencia" open={open} confirmLoading={loading} onCancel={onCancel}
    okText="Guardar y marcar para recálculo" cancelText="Cancelar"
    onOk={async () => { const v = await form.validateFields(); await onSubmit(v.fecha_corte_asistencia.format('YYYY-MM-DD')); }}>
    <p className="mb-4 text-sm text-gray-600">Las boletas vigentes quedarán marcadas para recálculo. No modifica boletas pagadas.</p>
    <Form form={form} layout="vertical">
      <Form.Item name="fecha_corte_asistencia" label="Fecha de corte de asistencia" rules={[{ required: true, message: 'Requerido' }]}>
        <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(date) => !date || date.isBefore(dayjs(ciclo?.fecha_inicio), 'day') || date.isAfter(dayjs(ciclo?.fecha_fin), 'day')} />
      </Form.Item>
    </Form>
  </Modal>;
}
