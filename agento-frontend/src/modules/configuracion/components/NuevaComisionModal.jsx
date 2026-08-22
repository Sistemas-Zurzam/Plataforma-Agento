import { DatePicker, Form, Input, InputNumber, Modal, Select } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

/**
 * Sirve tanto para "Nueva Comisión" (crea una vigencia nueva) como para
 * "Editar" (corrige el registro vigente más reciente, ver
 * ComisionAfpService::actualizar en el backend). En modo edición la AFP no
 * puede cambiar y se exige "Motivo" — una corrección debe quedar
 * justificada, a diferencia de registrar una vigencia nueva.
 */
export default function NuevaComisionModal({
  open,
  afps,
  editing = false,
  initialValues,
  onSubmit,
  onCancel,
  submitting,
}) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (open) {
      form.resetFields();
      if (editing && initialValues) {
        form.setFieldsValue({
          ...initialValues,
          vigencia_desde: initialValues.vigencia_desde ? dayjs(initialValues.vigencia_desde) : undefined,
        });
      } else {
        form.setFieldsValue({ vigencia_desde: dayjs() });
      }
    }
  }, [open, editing, initialValues, form]);

  const handleFinish = (values) => {
    onSubmit({
      ...values,
      vigencia_desde: values.vigencia_desde.format('YYYY-MM-DD'),
    });
  };

  return (
    <Modal
      title={editing ? 'Editar Comisión' : 'Nueva Comisión'}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText="Guardar"
      cancelText="Cancelar"
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={handleFinish}>
        <Form.Item
          label="AFP"
          name="afp_id"
          rules={[{ required: true, message: 'Selecciona una AFP' }]}
        >
          <Select
            placeholder="Selecciona una AFP"
            disabled={editing}
            options={afps?.map((afp) => ({ value: afp.afp_id, label: afp.nombre }))}
          />
        </Form.Item>

        <Form.Item
          label="Vigente desde"
          name="vigencia_desde"
          rules={[{ required: true, message: 'Selecciona la fecha de vigencia' }]}
        >
          <DatePicker className="w-full" format="DD/MM/YYYY" />
        </Form.Item>

        <div className="grid grid-cols-2 gap-x-4">
          <Form.Item
            label="Aporte obligatorio (%)"
            name="aporte_obligatorio_porcentaje"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>

          <Form.Item
            label="Prima seguro (%)"
            name="prima_seguro_porcentaje"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>

          <Form.Item
            label="Comisión flujo (%)"
            name="comision_flujo_porcentaje"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>

          <Form.Item
            label="Comisión mixta (%)"
            name="comision_mixta_porcentaje"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>

          <Form.Item
            label="Sobre saldo anual (%)"
            name="sobre_saldo_anual_porcentaje"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>
        </div>

        {editing && (
          <Form.Item
            label="Motivo de la corrección"
            name="motivo"
            rules={[{ required: true, message: 'Explica por qué se corrige este registro' }]}
          >
            <Input.TextArea rows={2} placeholder="Ej: se digitó mal la comisión de flujo al crearla" />
          </Form.Item>
        )}
      </Form>
    </Modal>
  );
}
