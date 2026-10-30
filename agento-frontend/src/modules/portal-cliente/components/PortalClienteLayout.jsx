import { LogoutOutlined } from '@ant-design/icons';
import { Avatar, ConfigProvider, Dropdown, Layout, Menu } from 'antd';
import { useState } from 'react';
import agentoLogo from '../../../assets/agento-logo.png';

const { Sider, Header: AntHeader, Content } = Layout;

/**
 * Layout común del Portal Cliente y del Portal de Empresa (gerencia).
 * menuItems llega ya filtrado por PortalClienteApp según los permisos y
 * capacidades que el backend devolvió en /api/portal/contexto — el backend
 * sigue siendo la autoridad real; esto solo evita mostrar opciones que de
 * todas formas rechazaría.
 */
export default function PortalClienteLayout({
  user,
  contexto,
  titulo = 'Portal Cliente',
  menuItems,
  seccion,
  tituloSeccion,
  onSelect,
  onLogout,
  children,
}) {
  const [collapsed, setCollapsed] = useState(false);
  const initial = user?.name?.charAt(0).toUpperCase();
  const nombreEmpresa = contexto?.empresa?.nombre_comercial ?? contexto?.empresa?.razon_social;

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
                {titulo}
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
              {tituloSeccion ?? menuItems.find((item) => item.key === seccion)?.label ?? 'Inicio'}
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
