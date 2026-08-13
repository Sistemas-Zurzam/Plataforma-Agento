import {
  ApartmentOutlined,
  CrownOutlined,
  SafetyCertificateOutlined,
  SmileOutlined,
  UserOutlined,
} from '@ant-design/icons';

const ICONS_BY_CLAVE = {
  administrador: <SafetyCertificateOutlined />,
  talento_cultura: <SmileOutlined />,
  gerencia: <CrownOutlined />,
  jefe_area: <ApartmentOutlined />,
  solicitante: <UserOutlined />,
};

const DEFAULT_ICON = <UserOutlined />;

export function iconForRole(clave) {
  return ICONS_BY_CLAVE[clave] ?? DEFAULT_ICON;
}
