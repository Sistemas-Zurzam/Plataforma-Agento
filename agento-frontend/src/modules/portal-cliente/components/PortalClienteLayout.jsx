import {
  ClockCircleOutlined,
  DollarOutlined,
  FileTextOutlined,
  GiftOutlined,
  HomeOutlined,
  LogoutOutlined,
  TeamOutlined,
  UserSwitchOutlined,
} from '@ant-design/icons';
import { Avatar, ConfigProvider, Dropdown, Layout, Menu } from 'antd';
import { useMemo, useState } from 'react';
import agentoLogo from '../../../assets/agento-logo.png';

const { Sider, Header: AntHeader, Content } = Layout;

// Cada entrada declara el permiso portal.* que el backend ya devolvió en
// /api/portal/contexto (permisos) — el backend sigue siendo la autoridad
// real; esto solo evita mostrar en el menú una opción que el backend de
// todas formas rechazaría. "Bonificaciones" se condiciona a
// portal.remuneraciones.ver (no a portal.bonificaciones.gestionar): ver
// (esta pantalla, todavía un placeholder de solo lectura) es parte de "ver
// remuneraciones"; portal.bonificaciones.gestionar es el permiso de
// escritura para las acciones futuras (validar/aprobar bonos), no para
// simplemente entrar a la sección. "Perfil de asistencia" no tiene ítem de
// menú propio — se llega a él desde una fila de Colaboradores, igual que
// el panel admin no tiene un menú separado para el detalle de un
// colaborador.
const MENU_ITEMS = [
  { key: 'resumen', icon: <HomeOutlined />, label: 'Resumen', permiso: 'portal.acceder' },
  { key: 'colaboradores', icon: <TeamOutlined />, label: 'Colaboradores', permiso: 'portal.asistencia.ver' },
  { key: 'incidencias', icon: <FileTextOutlined />, label: 'Incidencias', permiso: 'portal.asistencia.ver' },
  { key: 'horas-extra', icon: <ClockCircleOutlined />, label: 'Horas Extra', permiso: 'portal.horas_extra.ver' },
  { key: 'permisos', icon: <UserSwitchOutlined />, label: 'Permisos', permiso: 'portal.permisos.ver' },
  { key: 'planilla', icon: <DollarOutlined />, label: 'Planilla', permiso: 'portal.remuneraciones.ver' },
  { key: 'bonificaciones', icon: <GiftOutlined />, label: 'Bonificaciones', permiso: 'portal.remuneraciones.ver' },
];

export default function PortalClienteLayout({
  user,
  contexto,
  seccion,
  tituloSeccion,
  onSelect,
  onLogout,
  children,
}) {
  const [collapsed, setCollapsed] = useState(false);
  const initial = user?.name?.charAt(0).toUpperCase();
  const nombreEmpresa = contexto?.empresa?.nombre_comercial ?? contexto?.empresa?.razon_social;

  const menuItems = useMemo(() => {
    const permisos = contexto?.permisos ?? [];

    return MENU_ITEMS.filter((item) => permisos.includes(item.permiso));
  }, [contexto?.permisos]);

  const userMenuItems = [
    { key: 'logout', icon: <LogoutOutlined />, label: 'Cerrar sesión', onClick: onLogout },
  ];

  return (
    <Layout className="min-h-svh!">
      <Sider
        theme="light"
        collapsible
        collapsed={collapsed}
        onCollapse={setCollapsed}
        width={264}
        breakpoint="lg"
        collapsedWidth={0}
        zeroWidthTriggerStyle={{ top: 12 }}
        style={{ background: 'linear-gradient(165deg, #013063 0%, #001a3d 35%, #001225 100%)' }}
      >
        <div className="flex items-center gap-2.5 overflow-hidden border-b border-white/10 px-4 py-5">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white shadow-sm">
            <img src={agentoLogo} alt="Agento" className="h-full w-full scale-125 object-cover" />
          </div>
          {!collapsed && (
            <div className="min-w-0">
              <span className="block truncate text-base font-semibold tracking-tight text-white">
                Portal Cliente
              </span>
              {nombreEmpresa && (
                <span className="block truncate text-xs text-white/60">{nombreEmpresa}</span>
              )}
            </div>
          )}
        </div>

        <ConfigProvider
          theme={{
            token: {
              colorText: 'rgba(255, 255, 255, 0.95)',
              colorIcon: 'rgba(255, 255, 255, 0.95)',
            },
            components: {
              Menu: {
                itemBg: 'transparent',
                itemColor: 'rgba(255, 255, 255, 0.95)',
                itemHoverColor: '#ffffff',
                itemHoverBg: 'rgba(255, 255, 255, 0.1)',
                itemSelectedColor: '#014693',
                itemSelectedBg: '#ffffff',
                itemActiveBg: 'rgba(255, 255, 255, 0.1)',
                itemBorderRadius: 10,
                itemMarginBlock: 3,
              },
            },
          }}
        >
          <Menu
            mode="inline"
            selectedKeys={[seccion]}
            items={menuItems}
            onClick={({ key }) => onSelect(key)}
            style={{ borderInlineEnd: 'none', paddingTop: 8, background: 'transparent' }}
            className="px-2"
          />
        </ConfigProvider>
      </Sider>

      <Layout>
        <AntHeader
          className="flex items-center justify-between gap-4 border-b border-gray-100 px-6"
          style={{ background: '#fff', height: 'auto', lineHeight: 'normal', paddingBlock: 14 }}
        >
          <div className="min-w-0">
            <h1 className="truncate text-base font-semibold text-gray-900">
              {tituloSeccion ?? menuItems.find((item) => item.key === seccion)?.label ?? 'Resumen'}
            </h1>
            {nombreEmpresa && <p className="truncate text-xs text-gray-500">{nombreEmpresa}</p>}
          </div>

          <Dropdown menu={{ items: userMenuItems }} trigger={['click']}>
            <div className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-gray-50">
              <Avatar style={{ backgroundColor: '#014693' }}>{initial}</Avatar>
              <span className="hidden text-sm font-medium text-gray-900 sm:inline">{user?.name}</span>
            </div>
          </Dropdown>
        </AntHeader>

        <Content className="bg-gray-50 px-4 py-5 sm:px-6 sm:py-6">{children}</Content>
      </Layout>
    </Layout>
  );
}
