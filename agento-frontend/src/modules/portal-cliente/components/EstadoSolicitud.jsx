import { Tag, Typography } from 'antd';
import { ESTADOS_PORTAL, RESPONSABLES_PORTAL } from '../constants/estadosPortal';

/** Estado real de una solicitud y, si aplica, quién tiene el siguiente paso. */
export default function EstadoSolicitud({ estado, siguienteResponsable }) {
  const [etiqueta, color] = ESTADOS_PORTAL[estado] ?? [estado, 'default'];

  return (
    <div className="flex flex-col items-start gap-0.5">
      <Tag color={color} className="!m-0">{etiqueta}</Tag>
      {siguienteResponsable && (
        <Typography.Text type="secondary" className="text-xs">
          {RESPONSABLES_PORTAL[siguienteResponsable] ?? siguienteResponsable}
        </Typography.Text>
      )}
    </div>
  );
}
