import { Alert, App, Button, Form, Input } from 'antd';
import { useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';

/**
 * Identificación del candidato con código temporal enviado a su correo.
 * El mensaje tras solicitar el código es siempre el mismo, exista o no el correo.
 */
export default function VerificarCorreo({ acceso, onVerificado, descripcion }) {
  const { message } = App.useApp();
  const [email, setEmail] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [aviso, setAviso] = useState(null);

  const solicitar = async ({ email: correo }) => {
    setEnviando(true);
    try {
      const r = await acceso.solicitarCodigo(correo);
      setEmail(correo);
      setAviso(r.message);
    } catch (e) {
      message.error(e?.response?.status === 429 ? 'Demasiadas solicitudes. Espera un minuto e inténtalo de nuevo.' : mensajeDeError(e));
    } finally {
      setEnviando(false);
    }
  };

  const verificar = async ({ codigo }) => {
    setEnviando(true);
    try {
      await acceso.verificarCodigo(email, codigo);
      onVerificado?.();
    } catch (e) {
      message.error(e?.response?.status === 429 ? 'Demasiados intentos. Espera un minuto.' : mensajeDeError(e));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <div className="mx-auto max-w-md space-y-4 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Verifica tu correo</h2>
        <p className="text-sm text-gray-500">{descripcion ?? 'Te enviaremos un código de un solo uso para continuar.'}</p>
      </div>
      {!email ? (
        <Form layout="vertical" onFinish={solicitar} requiredMark={false}>
          <Form.Item name="email" label="Correo electrónico" rules={[{ required: true, type: 'email', message: 'Ingresa un correo válido.' }]}>
            <Input autoComplete="email" inputMode="email" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block loading={enviando}>Enviar código</Button>
        </Form>
      ) : (
        <Form layout="vertical" onFinish={verificar} requiredMark={false}>
          {aviso && <Alert type="info" showIcon className="mb-4" message={aviso} />}
          <Form.Item name="codigo" label="Código de 6 dígitos" rules={[{ required: true, pattern: /^\d{6}$/, message: 'Ingresa los 6 dígitos.' }]}>
            <Input inputMode="numeric" autoComplete="one-time-code" maxLength={6} />
          </Form.Item>
          <Button type="primary" htmlType="submit" block loading={enviando}>Verificar</Button>
          <Button type="link" block onClick={() => setEmail(null)}>Usar otro correo</Button>
        </Form>
      )}
    </div>
  );
}
