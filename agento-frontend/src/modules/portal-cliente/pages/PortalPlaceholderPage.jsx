import { ToolOutlined } from '@ant-design/icons';
import { Card, Typography } from 'antd';

const { Title, Paragraph } = Typography;

/**
 * Incremento 1 del Portal Cliente: solo navegación y seguridad. Ninguna
 * sección tiene todavía contenido funcional — cada una se reemplazará por su
 * pantalla real (consultas de asistencia/remuneraciones) en un incremento
 * posterior.
 */
export default function PortalPlaceholderPage({ titulo }) {
  return (
    <Card className="mx-auto max-w-2xl text-center">
      <ToolOutlined className="text-3xl text-agento-blue" />
      <Title level={4} className="mt-3!">
        {titulo}
      </Title>
      <Paragraph type="secondary">
        Esta sección del Portal Cliente todavía no tiene contenido funcional.
        Estará disponible en un próximo incremento.
      </Paragraph>
    </Card>
  );
}
