import { Form, Input, Modal, Select } from 'antd';
import { useEffect } from 'react';
import AreaSelect from './AreaSelect';

export default function EditarUsuarioModal({
  open,
  usuario,
  roles,
  onSubmit,
  onCancel,
  submitting,
  user,
}) {
  const [form] = Form.useForm();
  const puedeCrearArea = user?.permisos?.includes('areas.crear');
  const puedeCambiarRol = user?.permisos?.includes('usuarios.cambiar_rol');

  useEffect(() => {
    if (open && usuario) {
      form.setFieldsValue({
        name: usuario.name,
        username: usuario.username,
        email: usuario.email,
        area_id: usuario.area?.id,
        role_id: usuario.role?.id,
        password: '',
      });
    }
  }, [open, usuario, form]);

  return (
    <Modal
      title="Editar usuario"
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText="Guardar cambios"
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

        <Form.Item label="Área" name="area_id">
          <AreaSelect empresaId={usuario?.empresa?.id} puedeCrear={puedeCrearArea} />
        </Form.Item>

        {puedeCambiarRol && (
          <Form.Item
            label="Rol"
            name="role_id"
            rules={[{ required: true, message: 'Selecciona un rol' }]}
          >
            <Select
              placeholder="Selecciona un rol"
              options={roles?.map((role) => ({ value: role.id, label: role.nombre }))}
            />
          </Form.Item>
        )}

        <Form.Item
          label="Nueva contraseña"
          name="password"
          rules={[
            {
              validator: (_, value) => {
                if (!value) return Promise.resolve();
                if (value.length < 6) {
                  return Promise.reject(
                    new Error('La contraseña debe tener al menos 6 caracteres'),
                  );
                }
                return Promise.resolve();
              },
            },
          ]}
        >
          <Input.Password
            placeholder="Dejar en blanco para no cambiarla"
            autoComplete="new-password"
          />
        </Form.Item>
      </Form>
    </Modal>
  );
}
