import { Alert, DatePicker, Form, Input, Modal } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

/**
 * El cese solo registra hasta qué día se le paga al colaborador. El sueldo de
 * ese mes sale en la boleta del ciclo; los beneficios se liquidan aparte en
 * Nóminas → Liquidaciones.
 */
export default function CesarColaboradorModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const esHonorarios = colaborador?.regimen_laboral === 'Locacion de Servicios';
  const [form] = Form.useForm();

  useEffect(() => {
    if (open) form.setFieldsValue({ fecha_cese: dayjs(), motivo_cese: null });
  }, [open, form]);

  const guardar = (values) => onGuardar({ ...values, fecha_cese: values.fecha_cese.format('YYYY-MM-DD') });

  return (
    <Modal title="Registrar cese" open={open} onCancel={onCancel} onOk={() => form.submit()} okText="Registrar cese" okButtonProps={{ danger: true }} confirmLoading={submitting} width={560} centered destroyOnHidden>
      <Alert
        className="mb-4"
        type="info"
        showIcon
        message="La fecha de cese es el último día trabajado."
        description={esHonorarios
          ? 'El recibo por honorarios del mes se calculará hasta esa fecha.'
          : 'La boleta del mes pagará hasta esa fecha. La liquidación de beneficios (CTS, gratificación y vacaciones) se calcula después en Nóminas → Liquidaciones.'}
      />
      <Form form={form} layout="vertical" onFinish={guardar}>
        <Form.Item label="Fecha de cese (último día trabajado)" name="fecha_cese" rules={[{ required: true, message: 'Selecciona la fecha de cese' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" minDate={colaborador?.fecha_ingreso ? dayjs(colaborador.fecha_ingreso) : undefined} maxDate={dayjs()} />
        </Form.Item>
        <Form.Item label="Motivo del cese" name="motivo_cese" rules={[{ required: true, message: 'Indica el motivo del cese' }]}>
          <Input.TextArea rows={2} maxLength={255} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}
