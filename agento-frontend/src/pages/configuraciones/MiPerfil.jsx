import { App, Avatar, Button, Form, Input, Tag } from 'antd';
import { useEffect } from 'react';
import { useUpdateProfile } from '../../hooks/useUpdateProfile';

export default function MiPerfil({ user, onProfileUpdated }) {
  const [form] = Form.useForm();
  const { message } = App.useApp();
  const { updateProfile, loading } = useUpdateProfile();

  useEffect(() => {
    form.setFieldsValue({ name: user?.name, email: user?.email });
  }, [user, form]);

  const handleFinish = async (values) => {
    try {
      const updatedUser = await updateProfile(values);
      message.success('Perfil actualizado correctamente');
      onProfileUpdated?.(updatedUser);
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      if (fieldErrors) {
        form.setFields(
          Object.entries(fieldErrors).map(([field, errors]) => ({
            name: field,
            errors,
          })),
        );
      } else {
        message.error('No se pudo actualizar el perfil');
      }
    }
  };

  const initial = user?.name?.charAt(0).toUpperCase();
  const isAdmin = user?.role === 'administrador';

  return (
    <div>
      <h2 className="text-lg font-semibold text-gray-900">Mi Perfil</h2>
      <p className="mb-6 text-sm text-gray-500">
        Gestiona tu información personal y preferencias
      </p>

      <div className="mb-6 flex items-center gap-4">
        <Avatar size={56} style={{ backgroundColor: '#014693' }}>
          {initial}
        </Avatar>
        <div>
          <p className="font-medium text-gray-900">{user?.name}</p>
          <p className="text-sm text-gray-500">{user?.email}</p>
          <Tag color={isAdmin ? 'blue' : 'default'} className="mt-1">
            {isAdmin ? 'Administrador' : 'Colaborador'}
          </Tag>
        </div>
      </div>

      <Form form={form} layout="vertical" onFinish={handleFinish}>
        <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
          <Form.Item
            label="Nombre completo"
            name="name"
            rules={[{ required: true, message: 'Ingresa tu nombre completo' }]}
          >
            <Input size="large" />
          </Form.Item>

          <Form.Item
            label="Email"
            name="email"
            rules={[
              { required: true, message: 'Ingresa tu correo electrónico' },
              { type: 'email', message: 'Correo electrónico inválido' },
            ]}
          >
            <Input size="large" />
          </Form.Item>

          <Form.Item label="Empresa">
            <Input
              size="large"
              value={user?.empresa?.nombre_comercial ?? 'No asignada'}
              disabled
            />
          </Form.Item>

          <Form.Item label="Área">
            <Input size="large" value={user?.area?.nombre ?? 'No asignada'} disabled />
          </Form.Item>
        </div>

        <Form.Item className="mb-0 text-right">
          <Button
            type="primary"
            htmlType="submit"
            loading={loading}
            style={{
              background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
              border: 'none',
            }}
          >
            Guardar cambios
          </Button>
        </Form.Item>
      </Form>
    </div>
  );
}
