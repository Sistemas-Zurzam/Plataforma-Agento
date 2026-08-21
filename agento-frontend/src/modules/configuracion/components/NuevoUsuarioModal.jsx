import { Form, Input, Modal, Select } from 'antd';
import { useEffect } from 'react';
import AreaSelect from './AreaSelect';

/**
 * El campo subyacente (`empresa_ids`) siempre es un array — para
 * Administrador se comporta como un selector simple (1 empresa activa
 * inicial, ya que el rol le da acceso a todas automáticamente) y para el
 * resto de roles permite elegir varias.
 */
function EmpresaSelector({ value, onChange, empresas, multiple }) {
  const options = empresas.map((empresa) => ({ value: empresa.id, label: empresa.nombre }));

  if (multiple) {
    return (
      <Select
        mode="multiple"
        placeholder="Selecciona una o más empresas"
        value={value}
        onChange={onChange}
        options={options}
      />
    );
  }

  return (
    <Select
      placeholder="Selecciona la empresa activa inicial"
      value={value?.[0]}
      onChange={(seleccionado) => onChange(seleccionado !== undefined ? [seleccionado] : [])}
      options={options}
    />
  );
}

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
  const empresaIds = Form.useWatch('empresa_ids', form) ?? [];
  const roleId = Form.useWatch('role_id', form);
  const rolSeleccionado = roles.find((role) => role.id === roleId);
  const esAdministrador = rolSeleccionado?.clave === 'administrador';
  const empresasSeleccionadas = empresas.filter((empresa) => empresaIds.includes(empresa.id));
  const puedeCrearArea = user?.permisos?.includes('areas.crear');

  useEffect(() => {
    if (open) {
      form.resetFields();
      const empresaActiva = empresas.find((empresa) => empresa.es_activa);
      if (empresaActiva) {
        form.setFieldValue('empresa_ids', [empresaActiva.id]);
      }
    }
  }, [open, form, empresas]);

  // Al pasar a Administrador, solo se conserva la primera empresa elegida
  // (el rol ya da acceso a todas — un multi-select ahí no tendría sentido).
  useEffect(() => {
    if (esAdministrador && empresaIds.length > 1) {
      form.setFieldValue('empresa_ids', [empresaIds[0]]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [esAdministrador]);

  useEffect(() => {
    if (open) {
      form.setFieldValue('area_id', undefined);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, empresaIds.join(',')]);

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
          label="Rol"
          name="role_id"
          rules={[{ required: true, message: 'Selecciona un rol' }]}
        >
          <Select
            placeholder="Selecciona un rol"
            options={roles
              .filter(
                (role) =>
                  role.clave !== 'administrador' ||
                  empresasSeleccionadas.some((empresa) => empresa.role === 'administrador'),
              )
              .map((role) => ({
                value: role.id,
                label: role.nombre,
              }))}
          />
        </Form.Item>

        <Form.Item
          label="Empresa"
          name="empresa_ids"
          rules={[{ required: true, type: 'array', min: 1, message: 'Selecciona al menos una empresa' }]}
          extra={
            esAdministrador
              ? 'Como Administrador, tendrá acceso a todas las empresas automáticamente.'
              : undefined
          }
        >
          <EmpresaSelector empresas={empresas} multiple={!esAdministrador} />
        </Form.Item>

        <Form.Item label="Área" name="area_id">
          <AreaSelect
            key={empresaIds[0] ?? 'none'}
            empresaId={empresaIds[0]}
            disabled={!empresaIds[0]}
            puedeCrear={puedeCrearArea}
            onCreateError={(mensaje) =>
              form.setFields([{ name: 'area_id', errors: [mensaje] }])
            }
          />
        </Form.Item>
      </Form>
    </Modal>
  );
}
