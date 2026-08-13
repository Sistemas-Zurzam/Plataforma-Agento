import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { Alert, Button, Form, Input } from 'antd';
import agentoLogo from '../assets/agento-logo.png';
import { useAuth } from '../hooks/useAuth';

export default function LoginForm({ onSuccess }) {
  const { login, loading, error } = useAuth();

  const handleFinish = async (values) => {
    const ok = await login(values);
    if (ok) {
      onSuccess?.();
    }
  };

  return (
    <div className="flex min-h-svh items-center justify-center bg-gradient-to-br from-agento-blue-bright via-agento-blue to-agento-blue-dark px-4 py-10">
      <div className="flex w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl shadow-black/40 max-md:flex-col">
        <div className="relative flex flex-col justify-between overflow-hidden bg-gradient-to-br from-agento-blue-bright via-agento-blue to-agento-blue-dark p-10 md:w-1/2">
          <div className="pointer-events-none absolute -bottom-16 -left-16 h-56 w-56 rounded-full bg-agento-blue-dark/50" />
          <div className="pointer-events-none absolute -bottom-24 left-16 h-40 w-40 rounded-full bg-white/10" />
          <div className="pointer-events-none absolute -top-20 -right-14 h-40 w-40 rounded-full bg-white/10" />

          <div className="relative h-12 w-12 overflow-hidden rounded-full">
            <img
              src={agentoLogo}
              alt="Agento"
              className="h-full w-full scale-125 object-cover"
            />
          </div>

          <div className="relative mt-10">
            <h2 className="text-3xl font-bold text-white">Bienvenido</h2>
            <p className="mt-1 text-sm font-medium tracking-widest text-white/70 uppercase">
              Talento y cultura.
            </p>
            <p className="mt-4 text-sm leading-relaxed text-white/70">
              Ingresa con tu cuenta para gestionar a tu equipo, tu empresa y
              tus procesos de talento humano en un solo lugar.
            </p>
          </div>
        </div>

        <div className="flex flex-col justify-center p-10 md:w-1/2">
          <h1 className="text-2xl font-semibold text-agento-blue-dark">
            Iniciar sesión
          </h1>
          <p className="mt-1 mb-6 text-sm text-gray-500">
            Ingresa tus credenciales para continuar.
          </p>

          {error && (
            <Alert type="error" title={error} showIcon className="mb-4" />
          )}

          <Form layout="vertical" onFinish={handleFinish} disabled={loading}>
            <Form.Item
              label="Usuario o correo electrónico"
              name="login"
              rules={[
                { required: true, message: 'Ingresa tu usuario o correo electrónico' },
              ]}
            >
              <Input
                prefix={<UserOutlined className="text-gray-400" />}
                autoComplete="username"
                size="large"
              />
            </Form.Item>

            <Form.Item
              label="Contraseña"
              name="password"
              rules={[{ required: true, message: 'Ingresa tu contraseña' }]}
            >
              <Input.Password
                prefix={<LockOutlined className="text-gray-400" />}
                autoComplete="current-password"
                size="large"
              />
            </Form.Item>

            <Form.Item className="mb-0">
              <Button
                type="primary"
                htmlType="submit"
                loading={loading}
                size="large"
                block
                style={{
                  background:
                    'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
                  border: 'none',
                }}
              >
                Ingresar
              </Button>
            </Form.Item>
          </Form>
        </div>
      </div>
    </div>
  );
}
