import { CustomerServiceOutlined, InboxOutlined, LockOutlined } from '@ant-design/icons';
import { App, Button, Drawer, Form, Input, Radio, Select, Upload } from 'antd';

const TIPO_OPTIONS = [
  { value: 'incidencia_tecnica', label: 'Incidencia técnica' },
  { value: 'solicitud_acceso', label: 'Solicitud de acceso' },
  { value: 'consulta_general', label: 'Consulta general' },
  { value: 'error_sistema', label: 'Error en el sistema' },
  { value: 'otro', label: 'Otro' },
];

const PRIORIDADES = [
  { value: 'baja', label: 'Baja', color: '#22c55e' },
  { value: 'media', label: 'Media', color: '#f59e0b' },
  { value: 'alta', label: 'Alta', color: '#ef4444' },
  { value: 'urgente', label: 'Urgente', color: '#dc2626' },
];

/**
 * Maqueta de UI únicamente — sin backend todavía. "Enviar solicitud" no
 * persiste nada, solo confirma visualmente (Sección pedida por el usuario:
 * "solo maquétame sin backend"). El botón de acción usa el degradado
 * agento-blue oficial (no el verde de la referencia) para no introducir un
 * color fuera del Design System.
 */
export default function SoporteDrawer({ open, onClose, onVerTickets }) {
  const [form] = Form.useForm();
  const { message } = App.useApp();

  const handleFinish = () => {
    message.success('Solicitud registrada (maqueta) — todavía no se envía a ningún sistema real.');
    form.resetFields();
    onClose();
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={380}
      closeIcon={<span className="text-lg">&times;</span>}
      title={
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-agento-blue-light text-agento-blue">
            <CustomerServiceOutlined />
          </span>
          <div>
            <p className="font-semibold text-gray-900">Soporte</p>
            <p className="text-xs font-normal text-gray-500">Solicita ayuda a nuestro equipo de TI</p>
          </div>
        </div>
      }
      footer={
        <div className="flex flex-col gap-3">
          <div className="flex gap-2">
            <Button block onClick={onClose}>
              Cancelar
            </Button>
            <Button
              block
              type="primary"
              onClick={() => form.submit()}
              style={{ background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)', border: 'none' }}
            >
              Enviar solicitud
            </Button>
          </div>
          <p className="text-center text-xs text-gray-500">
            Puedes seguir tus solicitudes en{' '}
            <button type="button" className="text-agento-blue-bright hover:underline" onClick={onVerTickets}>
              Tickets Soporte
            </button>
            .
          </p>
        </div>
      }
    >
      <Form form={form} layout="vertical" onFinish={handleFinish} initialValues={{ prioridad: 'media' }}>
        <Form.Item
          label="Tipo de solicitud"
          name="tipo"
          rules={[{ required: true, message: 'Selecciona un tipo' }]}
        >
          <Select placeholder="Selecciona un tipo" options={TIPO_OPTIONS} />
        </Form.Item>

        <Form.Item
          label="Asunto"
          name="asunto"
          rules={[{ required: true, message: 'Describe brevemente el problema o solicitud' }]}
        >
          <Input placeholder="Describe brevemente el problema o solicitud" />
        </Form.Item>

        <Form.Item label="Descripción detallada" name="descripcion">
          <Input.TextArea rows={4} placeholder="Cuéntanos más detalles para entender mejor el caso..." />
        </Form.Item>

        <Form.Item label="Prioridad" name="prioridad">
          <Radio.Group className="flex flex-wrap gap-x-4 gap-y-2">
            {PRIORIDADES.map((prioridad) => (
              <Radio key={prioridad.value} value={prioridad.value}>
                <span className="inline-flex items-center gap-1.5">
                  <span
                    className="inline-block h-2.5 w-2.5 rounded-full"
                    style={{ backgroundColor: prioridad.color }}
                  />
                  {prioridad.label}
                </span>
              </Radio>
            ))}
          </Radio.Group>
        </Form.Item>

        <Form.Item label="Adjuntar imágenes (opcional)" name="adjuntos">
          <Upload.Dragger multiple beforeUpload={() => false} showUploadList={{ showRemoveIcon: true }}>
            <p className="text-2xl text-gray-300">
              <InboxOutlined />
            </p>
            <p className="text-sm text-gray-600">Arrastra tus imágenes aquí</p>
            <p className="text-xs text-gray-400">o haz clic para seleccionar varias</p>
          </Upload.Dragger>
        </Form.Item>

        <div className="flex items-start gap-2 rounded-lg bg-agento-blue-light/60 p-3 text-xs text-agento-blue-dark">
          <LockOutlined className="mt-0.5" />
          <span>Tu solicitud será registrada a tu nombre y asignada a nuestro equipo de TI.</span>
        </div>
      </Form>
    </Drawer>
  );
}
