import { DatePicker, Form, Input, Modal } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

export default function NuevoCicloModal({ open, onCancel, onSubmit, loading, ciclo }) {
  const [form] = Form.useForm();
  const editando = !!ciclo;

  useEffect(() => {
    if (!open) {
      form.resetFields();
      return;
    }
    if (ciclo) {
      form.setFieldsValue({
        nombre: ciclo.nombre,
        fecha_inicio: dayjs(ciclo.fecha_inicio),
        fecha_fin: dayjs(ciclo.fecha_fin),
        fecha_corte_asistencia: dayjs(ciclo.fecha_corte_asistencia),
        fecha_pago: dayjs(ciclo.fecha_pago),
      });
    }
  }, [open, ciclo, form]);

  const handleOk = async () => {
    const values = await form.validateFields();
    await onSubmit({
      nombre: values.nombre,
      fecha_inicio: values.fecha_inicio.format('YYYY-MM-DD'),
      fecha_fin: values.fecha_fin.format('YYYY-MM-DD'),
      fecha_corte_asistencia: values.fecha_corte_asistencia.format('YYYY-MM-DD'),
      fecha_pago: values.fecha_pago.format('YYYY-MM-DD'),
    });
    form.resetFields();
  };

  return (
    <Modal
      title={editando ? 'Editar ciclo remunerativo' : 'Nuevo ciclo remunerativo'}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText={editando ? 'Guardar cambios' : 'Crear ciclo'}
      cancelText="Cancelar"
      width={{ xs: '95%', sm: '85%', md: 560 }}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" className="mt-4">
        <Form.Item name="nombre" label="Nombre del ciclo" rules={[{ required: true, message: 'Ingresa un nombre' }]}>
          <Input placeholder="Ej. Planilla Agosto 2026" />
        </Form.Item>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
          <Form.Item name="fecha_inicio" label="Fecha de inicio" rules={[{ required: true, message: 'Requerido' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="fecha_fin" label="Fecha de fin" rules={[{ required: true, message: 'Requerido' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="fecha_corte_asistencia" label="Fecha de corte de asistencia" rules={[{ required: true, message: 'Requerido' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="fecha_pago" label="Fecha de pago" rules={[{ required: true, message: 'Requerido' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
        </div>
      </Form>
    </Modal>
  );
}
