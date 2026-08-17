import { Form, InputNumber, Modal, Select } from 'antd';
import { useEffect } from 'react';
import { useHorarios } from '../../asistencia/hooks/useHorarios';
import { MODALIDAD_TRABAJO_OPTIONS } from '../constants/opciones';

export default function EditarHorarioColaboradorModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  const { horarios, fetchHorarios, loading } = useHorarios();

  useEffect(() => {
    if (!open) return;
    fetchHorarios(1, 50, '', 'activo');
    form.setFieldsValue({
      horario_id: colaborador?.horario?.id,
      modalidad_trabajo: colaborador?.modalidad_trabajo,
      tolerancia_particular_minutos: colaborador?.tolerancia_particular_minutos,
    });
  }, [open, colaborador, fetchHorarios, form]);

  return (
    <Modal
      title="Editar horario"
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      okText="Guardar"
      cancelText="Cancelar"
      confirmLoading={submitting}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={onGuardar}>
        <Form.Item label="Horario" name="horario_id" rules={[{ required: true, message: 'Selecciona un horario' }]}>
          <Select loading={loading} options={horarios.map((horario) => ({ value: horario.id, label: horario.nombre }))} />
        </Form.Item>
        <Form.Item label="Modalidad de trabajo" name="modalidad_trabajo" rules={[{ required: true, message: 'Selecciona una modalidad' }]}>
          <Select options={MODALIDAD_TRABAJO_OPTIONS} />
        </Form.Item>
        <Form.Item label="Tolerancia particular (minutos)" name="tolerancia_particular_minutos" extra="Déjalo vacío para usar la tolerancia del horario.">
          <InputNumber min={0} className="w-full" placeholder="Usar la del horario" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
