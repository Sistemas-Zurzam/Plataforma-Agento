import { Form, Input, Modal, Select, Switch } from 'antd';
import { useEffect } from 'react';
import { REGIMEN_OPTIONS } from '../constants/regimenLaboral';
import ColorSwatchPicker from './ColorSwatchPicker';
import EmpresaSedes from './EmpresaSedes';

export default function EmpresaFormModal({
  open,
  title,
  initialValues,
  onSubmit,
  onCancel,
  submitting,
  user,
}) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (open) {
      form.setFieldsValue({
        nombre: initialValues?.nombre ?? '',
        abreviatura: initialValues?.abreviatura ?? '',
        grupo: initialValues?.grupo ?? '',
        ruc: initialValues?.ruc ?? '',
        direccion: initialValues?.direccion ?? '',
        color: initialValues?.color ?? null,
        regimen_laboral: initialValues?.regimen_laboral ?? null,
        inscrita_remype: initialValues?.inscrita_remype ?? false,
      });
    }
  }, [open, initialValues, form]);

  const handleFinish = (values) => {
    onSubmit(values, form);
  };

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
      centered
    >
      <Form form={form} layout="vertical" onFinish={handleFinish}>
        <Form.Item
          label="Nombre de la empresa"
          name="nombre"
          rules={[{ required: true, message: 'Ingresa el nombre de la empresa' }]}
        >
          <Input placeholder="Ej: Mi Empresa SAC" />
        </Form.Item>

        <div className="grid grid-cols-2 gap-x-4">
          <Form.Item label="Abreviatura" name="abreviatura">
            <Input maxLength={10} placeholder="Ej: ME" />
          </Form.Item>

          <Form.Item label="Grupo" name="grupo">
            <Input placeholder="Ej: Agento" />
          </Form.Item>
        </div>

        <Form.Item label="Color" name="color">
          <ColorSwatchPicker />
        </Form.Item>

        <Form.Item
          label="RUC (opcional)"
          name="ruc"
          rules={[
            { len: 11, message: 'El RUC debe tener 11 dígitos' },
            { pattern: /^\d*$/, message: 'El RUC solo debe contener números' },
          ]}
        >
          <Input maxLength={11} placeholder="20XXXXXXXXX" />
        </Form.Item>

        <Form.Item label="Dirección (opcional)" name="direccion">
          <Input placeholder="Av. Principal 123, Lima" />
        </Form.Item>

        <Form.Item
          label="Régimen Laboral"
          name="regimen_laboral"
          extra="Define cómo se calculará la planilla de todos los colaboradores de esta empresa."
        >
          <Select
            allowClear
            placeholder="Sin especificar"
            options={REGIMEN_OPTIONS}
          />
        </Form.Item>

        <Form.Item
          label="Inscrita en REMYPE"
          name="inscrita_remype"
          valuePropName="checked"
        >
          <Switch />
        </Form.Item>
      </Form>

      {initialValues?.id && (
        <div className="mt-2 mb-4">
          <EmpresaSedes empresaId={initialValues.id} user={user} />
        </div>
      )}
    </Modal>
  );
}
