import { DatePicker, Form, InputNumber, Modal, Select, Table } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import { useHorarios } from '../../asistencia/hooks/useHorarios';
import { MODALIDAD_TRABAJO_OPTIONS } from '../constants/opciones';

const HISTORIAL_COLUMNS = [
  { title: 'Horario', dataIndex: 'horario' },
  { title: 'Desde', dataIndex: 'vigencia_desde' },
  { title: 'Hasta', dataIndex: 'vigencia_hasta', render: (v) => v ?? 'Vigente' },
];

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
      vigencia_desde: dayjs(),
      vigencia_hasta: undefined,
    });
  }, [open, colaborador, fetchHorarios, form]);

  const handleFinish = (values) => {
    onGuardar({
      ...values,
      vigencia_desde: values.vigencia_desde.format('YYYY-MM-DD'),
      vigencia_hasta: values.vigencia_hasta ? values.vigencia_hasta.format('YYYY-MM-DD') : null,
    });
  };

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
      <Form form={form} layout="vertical" onFinish={handleFinish}>
        <Form.Item label="Horario" name="horario_id" rules={[{ required: true, message: 'Selecciona un horario' }]}>
          <Select loading={loading} options={horarios.map((horario) => ({ value: horario.id, label: horario.nombre }))} />
        </Form.Item>
        <Form.Item label="Modalidad de trabajo" name="modalidad_trabajo" rules={[{ required: true, message: 'Selecciona una modalidad' }]}>
          <Select options={MODALIDAD_TRABAJO_OPTIONS} />
        </Form.Item>
        <Form.Item label="Tolerancia particular (minutos)" name="tolerancia_particular_minutos" extra="Déjalo vacío para usar la tolerancia del horario.">
          <InputNumber min={0} className="w-full" placeholder="Usar la del horario" />
        </Form.Item>
        <div className="grid grid-cols-2 gap-3">
          <Form.Item
            label="Vigente desde"
            name="vigencia_desde"
            extra="Desde qué día este horario afecta el procesamiento de marcaciones."
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(d) => d && d.isAfter(dayjs(), 'day')} />
          </Form.Item>
          <Form.Item label="Vigente hasta (opcional)" name="vigencia_hasta">
            <DatePicker className="w-full" format="DD/MM/YYYY" placeholder="Indefinido" />
          </Form.Item>
        </div>
      </Form>

      {colaborador?.historial_horario?.length > 0 && (
        <div className="mt-4">
          <p className="mb-2 text-xs font-semibold tracking-wide text-gray-400 uppercase">Historial de horario</p>
          <Table
            rowKey="id"
            size="small"
            dataSource={colaborador.historial_horario}
            columns={HISTORIAL_COLUMNS}
            pagination={false}
          />
        </div>
      )}
    </Modal>
  );
}
