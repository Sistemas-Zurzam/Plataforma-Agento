import { Form, Input, InputNumber, Modal, Select, DatePicker } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

export default function AgregarHorasExtraBoletaModal({ open, onCancel, onSubmit, loading, boleta, ciclo }) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (!open) form.resetFields();
  }, [open, form]);

  const handleOk = async () => {
    const values = await form.validateFields();
    await onSubmit({
      fecha: values.fecha.format('YYYY-MM-DD'),
      minutos: values.minutos,
      tasa: values.tasa,
      motivo: values.motivo.trim(),
    });
    form.resetFields();
  };

  return (
    <Modal
      title={`Agregar horas extra — ${boleta?.colaborador?.nombre_completo ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText="Registrar y recalcular boleta"
      cancelText="Cancelar"
      width={{ xs: '95%', sm: 520 }}
      destroyOnHidden
    >
      <p className="mb-4 text-sm text-gray-500">
        Se recalculará solo esta boleta. La versión anterior quedará en el historial. Ingresa el total de minutos para esta fecha y tasa.
      </p>
      <Form form={form} layout="vertical">
        <Form.Item label="Fecha" name="fecha" rules={[{ required: true, message: 'Selecciona la fecha' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(fecha) => (
            (ciclo?.fecha_inicio && fecha.isBefore(dayjs(ciclo.fecha_inicio), 'day'))
            || (ciclo?.fecha_fin && fecha.isAfter(dayjs(ciclo.fecha_fin), 'day'))
          )} />
        </Form.Item>
        <div className="grid grid-cols-2 gap-4">
          <Form.Item label="Minutos" name="minutos" rules={[{ required: true, message: 'Ingresa los minutos' }]}>
            <InputNumber className="w-full" min={1} max={1440} precision={0} addonAfter="min" />
          </Form.Item>
          <Form.Item label="Tasa" name="tasa" rules={[{ required: true, message: 'Selecciona la tasa' }]}>
            <Select options={[
              { value: '25', label: '25%' },
              { value: '35', label: '35%' },
              { value: '100', label: '100% — feriado/descanso' },
            ]} />
          </Form.Item>
        </div>
        <Form.Item label="Motivo / sustento" name="motivo" rules={[
          { required: true, whitespace: true, message: 'Indica el motivo' },
          { min: 5, message: 'El motivo debe tener al menos 5 caracteres' },
          { max: 1000, message: 'Máximo 1000 caracteres' },
        ]}>
          <Input.TextArea rows={3} maxLength={1000} showCount placeholder="Ej. Trabajo autorizado que no se registró en asistencia" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
