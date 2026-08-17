import { Form, Input, Modal, Select } from 'antd';
import { useEffect } from 'react';
import AreaSelect from './AreaSelect';

export default function NuevoUsuarioModal({
  open,
  roles,
  empresas,
  onSubmit,
  onCancel,
  submitting,
  user,
}) {
  const [form] = Form.useForm();
  const empresaId = Form.useWatch('empresa_id', form);
  const empresaSeleccionada = empresas.find((empresa) => empresa.id === empresaId);
  const puedeCrearArea = user?.permisos?.includes('areas.crear');

  useEffect(() => {
    if (open) {
      form.resetFields();
      const empresaActiva = empresas.find((empresa) => empresa.es_activa);
      if (empresaActiva) {
        form.setFieldValue('empresa_id', empresaActiva.id);
      }
    }
  }, [open, form, empresas]);

  useEffect(() => {
    if (open && empresaId) {
      form.setFieldValue('area_id', undefined);
    }
  }, [open, empresaId, form]);

  return (
    <Modal
      title="Nuevo usuario"
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText="Crear"
      cancelText="Cancelar"
      destroyOnHidden
      centered
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(values) => onSubmit(values, form)}
      >
        <Form.Item
          label="Nombre completo"
          name="name"
          rules={[{ required: true, message: 'Ingresa el nombre completo' }]}
        >
          <Input placeholder="Ej: Ana Torres" />
        </Form.Item>

        <Form.Item
          label="Usuario"
          name="username"
          rules={[{ required: true, message: 'Ingresa el nombre de usuario' }]}
        >
          <Input placeholder="ana.torres" autoComplete="off" />
        </Form.Item>

        <Form.Item
          label="Email"
          name="email"
          rules={[
            { required: true, message: 'Ingresa el correo electrónico' },
            { type: 'email', message: 'Correo electrónico inválido' },
          ]}
        >
          <Input placeholder="ana.torres@empresa.com" />
        </Form.Item>

        <Form.Item
          label="Contraseña"
          name="password"
          rules={[
            { required: true, message: 'Ingresa una contraseña' },
            { min: 6, message: 'La contraseña debe tener al menos 6 caracteres' },
          ]}
        >
          <Input.Password placeholder="Mínimo 6 caracteres" autoComplete="new-password" />
        </Form.Item>

        <Form.Item
          label="Empresa"
          name="empresa_id"
          rules={[{ required: true, message: 'Selecciona una empresa' }]}
        >
          <Select
            placeholder="Selecciona una empresa"
            options={empresas.map((empresa) => ({
              value: empresa.id,
              label: empresa.nombre,
            }))}
          />
        </Form.Item>

        <Form.Item label="Área" name="area_id">
          <AreaSelect
            key={empresaId ?? 'none'}
            empresaId={empresaId}
            disabled={!empresaId}
            puedeCrear={puedeCrearArea}
            onCreateError={(mensaje) =>
              form.setFields([{ name: 'area_id', errors: [mensaje] }])
            }
          />
        </Form.Item>

        <Form.Item
          label="Rol"
          name="role_id"
          rules={[{ required: true, message: 'Selecciona un rol' }]}
        >
          <Select
            placeholder="Selecciona un rol"
            options={roles
              .filter(
                (role) => role.clave !== 'administrador' || empresaSeleccionada?.role === 'administrador',
              )
              .map((role) => ({
              value: role.id,
              label: role.nombre,
              }))}
          />
        </Form.Item>
      </Form>
    </Modal>
  );
}
