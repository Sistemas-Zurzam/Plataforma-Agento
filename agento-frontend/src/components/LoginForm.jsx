import { Alert, Button, Form, Input } from 'antd';
import agentoBrandMark from '../assets/agento-brand-mark.svg';
import { useAuth } from '../hooks/useAuth';

/**
 * Medidas y estructura verificadas contra el archivo real de Figma
 * ("T-01 — Login", frame "Login / Default", node 1:344) con
 * get_metadata + captura — no adivinadas de una imagen suelta:
 *   - Panel de marca / panel de formulario: 600/1440 y 840/1440 del ancho
 *     total (5/12 y 7/12 exactos).
 *   - Panel de marca: margen de 56px en los 4 lados (logo, bloque de
 *     titular y copyright quedan repartidos por justify-between, que
 *     reproduce el mismo espaciado que mide Figma entre esos 3 bloques).
 *   - Dos círculos decorativos concéntricos, ambos anclados en (56,56):
 *     520px y 600px de diámetro.
 *   - Columna del formulario: 400px de ancho, centrada horizontal y
 *     verticalmente dentro del panel; 28px de separación constante entre
 *     cada bloque (título, campo, campo, botón, texto de ayuda).
 *
 * El campo "login" acepta usuario O correo electrónico por igual (ver
 * LoginRequest::credentials() en el backend, que detecta cuál es según el
 * formato) — la etiqueta y el placeholder reflejan eso, aunque Figma
 * muestra solo "Correo electrónico": simplificar a "solo email" confundiría
 * a quienes ya inician sesión con su usuario.
 *
 * El diseño de Figma también incluye "¿Olvidaste tu contraseña?" y
 * "Recordarme" — ninguno de los dos tiene nada detrás en este backend (no
 * hay flujo de recuperación de contraseña, ni sesión extendida distinta
 * según un checkbox), así que se omiten a propósito en vez de mostrar
 * controles que no hacen nada.
 */
export default function LoginForm({ onSuccess }) {
  const { login, loading, error } = useAuth();
  const anioActual = new Date().getFullYear();

  const handleFinish = async (values) => {
    const ok = await login(values);
    if (ok) {
      onSuccess?.();
    }
  };

  return (
    <div className="flex min-h-svh w-full flex-col md:flex-row">
      <div className="relative flex w-full flex-col justify-between overflow-hidden bg-agento-blue p-8 md:min-h-svh md:w-5/12 md:p-14">
        <div className="pointer-events-none absolute top-14 left-14 h-[600px] w-[600px] rounded-full bg-agento-blue-bright/10" />
        <div className="pointer-events-none absolute top-14 left-14 h-[520px] w-[520px] rounded-full bg-agento-blue-bright/15" />

        <img src={agentoBrandMark} alt="Agento" className="relative z-10 h-[63px] w-[83px]" />

        <div className="relative z-10 mt-12 max-w-[420px] md:mt-0">
          <h1 className="text-3xl leading-[1.2] font-bold text-white">
            Gestiona tu planilla, tu talento y tu cultura en un solo lugar.
          </h1>
          <p className="mt-4 text-sm leading-relaxed text-white/70">
            La plataforma que une nómina, selección y reclutamiento para
            simplificar la gestión de tu gente.
          </p>
        </div>

        <p className="relative z-10 mt-12 text-xs text-white/50 md:mt-0">
          © {anioActual} Out Sourcing Zurzam S.A.C. Todos los derechos reservados.
        </p>
      </div>

      <div className="flex w-full flex-1 flex-col justify-center bg-white px-6 py-10 md:px-16">
        <div className="mx-auto flex w-full max-w-[400px] flex-col gap-7">
          <div>
            <h2 className="text-[28px] leading-9 font-semibold text-gray-900">
              Iniciar sesión
            </h2>
            <p className="mt-2 text-sm leading-6 text-gray-500">
              Ingresa tus credenciales para acceder a tu cuenta de Agento.
            </p>
          </div>

          {error && (
            <Alert type="error" message={error} showIcon className="!mb-0" />
          )}

          <Form layout="vertical" onFinish={handleFinish} disabled={loading} className="contents">
            <Form.Item
              label="Usuario o correo electrónico"
              name="login"
              className="!mb-0"
              rules={[
                { required: true, message: 'Ingresa tu usuario o correo electrónico' },
              ]}
            >
              <Input
                placeholder="usuario o nombre@empresa.com"
                autoComplete="username"
                size="large"
              />
            </Form.Item>

            <Form.Item
              label="Contraseña"
              name="password"
              className="!mb-0"
              rules={[{ required: true, message: 'Ingresa tu contraseña' }]}
            >
              <Input.Password autoComplete="current-password" size="large" />
            </Form.Item>

            <Form.Item className="!mb-0">
              <Button
                type="primary"
                htmlType="submit"
                loading={loading}
                size="large"
                block
                style={{ background: '#014693', border: 'none' }}
              >
                Iniciar sesión
              </Button>
            </Form.Item>
          </Form>

          <p className="text-center text-xs text-gray-400">
            ¿Necesitas ayuda? Contacta a tu administrador de sistema.
          </p>
        </div>
      </div>
    </div>
  );
}
