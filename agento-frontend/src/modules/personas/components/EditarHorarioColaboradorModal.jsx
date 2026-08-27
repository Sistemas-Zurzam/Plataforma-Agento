import { Alert, DatePicker, Form, InputNumber, Modal, Select, Table } from 'antd';
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
  const horarioIdSeleccionado = Form.useWatch('horario_id', form);
  const horarioSeleccionado = horarios.find((horario) => horario.id === horarioIdSeleccionado);
  const esRotativo = horarioSeleccionado?.tipo_turno === 'rotativo';

  useEffect(() => {
    if (!open) return;
    fetchHorarios(1, 50, '', 'activo');
    form.setFieldsValue({
      horario_id: colaborador?.horario?.id,
      modalidad_trabajo: colaborador?.modalidad_trabajo,
      tolerancia_particular_minutos: colaborador?.tolerancia_particular_minutos,
      dias_descanso_rotativo_por_semana: colaborador?.dias_descanso_rotativo_por_semana,
      vigencia_desde: dayjs(),
      vigencia_hasta: undefined,
    });
  }, [open, colaborador, fetchHorarios, form]);

  const handleFinish = (values) => {
    const horarioSeleccionado = horarios.find((horario) => horario.id === values.horario_id);
    onGuardar({
      ...values,
      horario_nombre: horarioSeleccionado?.nombre,
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

        {esRotativo && (
          <>
            <Alert
              type="warning"
              showIcon
              className="mb-3"
              message="Horario rotativo"
              description="El sistema nunca adivina el día de descanso — deberás declarar manualmente en el calendario del colaborador cuáles fechas son su descanso cada mes."
            />
            <Form.Item
              label="Días de descanso a la semana"
              name="dias_descanso_rotativo_por_semana"
              extra="Cuántos días libres le corresponden por semana (varía por persona: 1, 2, 3...)."
              rules={[{ required: true, message: 'Obligatorio para un horario rotativo' }]}
            >
              <InputNumber min={1} max={6} className="w-full" />
            </Form.Item>
          </>
        )}

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
