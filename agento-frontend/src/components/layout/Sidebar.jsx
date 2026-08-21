import {
  BarChartOutlined,
  ClockCircleOutlined,
  CustomerServiceOutlined,
  SettingOutlined,
  TeamOutlined,
  UserSwitchOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { ConfigProvider, Layout, Menu } from 'antd';
import { useMemo } from 'react';
import agentoLogo from '../../assets/agento-logo.png';
import FileInvoiceDollarIcon from '../icons/FileInvoiceDollarIcon';

const { Sider } = Layout;

/**
 * "Gestión de personas" ya tiene una funcionalidad real parcial (el botón
 * "Gestión de horarios" dentro de ella, ver GestionPersonas.jsx) — por eso
 * se habilita para cualquiera con el permiso horarios.ver, no solo para
 * isAdmin. Gestión de horarios NO tiene su propia entrada de sidebar a
 * propósito: en Agento (como en la referencia) es un botón dentro de
 * Gestión de personas, no un módulo aparte. "Gestión de remuneraciones" ya
 * tiene motor de cálculo y UI real (ver GestionRemuneraciones.jsx) — se
 * habilita con el permiso nominas.ver.
 */
function buildMenuItems(isAdmin, puedeVerHorarios, puedeVerAsistencia, puedeVerNominas) {
  const principal = [];

  if (isAdmin || puedeVerHorarios || puedeVerNominas) {
    principal.push({
      key: 'nominas',
      icon: <FileInvoiceDollarIcon />,
      label: 'Nóminas',
      children: [
        {
          key: 'nominas-personas',
          icon: <TeamOutlined />,
          label: 'Gestión de personas',
        },
        {
          key: 'nominas-remuneraciones',
          icon: <WalletOutlined />,
          label: 'Gestión de remuneraciones',
          disabled: !isAdmin && !puedeVerNominas,
        },
        {
          key: 'nominas-asistencias',
          icon: <ClockCircleOutlined />,
          label: 'Gestión de asistencias',
          disabled: !isAdmin && !puedeVerAsistencia,
        },
      ],
    });
  }

  if (isAdmin) {
    principal.push(
      {
        key: 'seleccion',
        icon: <UserSwitchOutlined />,
        label: 'Selección y reclutamiento',
        disabled: true,
      },
      {
        key: 'reportes',
        icon: <BarChartOutlined />,
        label: 'Reportes',
        disabled: true,
      },
    );
  }

  // Configuraciones es una única pantalla (Mi Perfil/Empresas/Usuarios y
  // Roles/Permisos/Parámetros Remunerativos viven como pestañas internas de
  // GestionEmpresas, no como secciones de sidebar separadas) — por eso es un
  // ítem directo, sin submenú con un solo hijo ("Gestión de empresas" ya no
  // tiene entrada propia).
  const sistema = [
    {
      key: 'configuraciones-empresas',
      icon: <SettingOutlined />,
      label: 'Configuraciones',
    },
  ];

  // La tabla de gestión de tickets es una vista de triage para quien
  // resuelve soporte, no algo que un usuario normal necesite — crear un
  // ticket es aparte (botón "Soporte" en el Header, visible para todos).
  // Maqueta de UI únicamente, sin backend todavía.
  if (isAdmin) {
    sistema.push({
      key: 'soporte-tickets',
      icon: <CustomerServiceOutlined />,
      label: 'Soporte',
    });
  }

  const items = [];

  if (principal.length > 0) {
    items.push({ key: 'grupo-principal', type: 'group', label: 'Principal', children: principal });
  }

  items.push({ key: 'grupo-sistema', type: 'group', label: 'Sistema', children: sistema });

  return items;
}

export default function Sidebar({ collapsed, onCollapse, selectedKey, onSelect, user }) {
  const isAdmin = user?.role === 'administrador';
  const puedeVerHorarios = user?.permisos?.includes('horarios.ver');
  const puedeVerAsistencia = user?.permisos?.includes('asistencia.ver');
  const puedeVerNominas = user?.permisos?.includes('nominas.ver');
  const menuItems = useMemo(
    () => buildMenuItems(isAdmin, puedeVerHorarios, puedeVerAsistencia, puedeVerNominas),
    [isAdmin, puedeVerHorarios, puedeVerAsistencia, puedeVerNominas],
  );

  return (
    <Sider
      theme="light"
      collapsible
      collapsed={collapsed}
      onCollapse={onCollapse}
      width={264}
      breakpoint="lg"
      collapsedWidth={0}
      zeroWidthTriggerStyle={{ top: 12 }}
      style={{ background: 'linear-gradient(165deg, #013063 0%, #001a3d 35%, #001225 100%)' }}
    >
      <div className="flex items-center gap-2.5 overflow-hidden border-b border-white/10 px-4 py-5">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white shadow-sm">
          <img
            src={agentoLogo}
            alt="Agento"
            className="h-full w-full scale-125 object-cover"
          />
        </div>
        {!collapsed && (
          <span className="truncate text-lg font-semibold tracking-tight text-white">
            Agento
          </span>
        )}
      </div>

      {/* Tokens propios solo para este Menu (no globales): el fondo del
          sidebar ya es el degradado de arriba, así que el Menu en sí debe
          quedar transparente con texto claro — mismo mecanismo oficial de
          ConfigProvider anidado, en vez de pelear con clases internas de
          AntD (eso fue lo que causó el corte feo con theme="dark"). */}
      <ConfigProvider
        theme={{
          token: {
            colorText: 'rgba(255, 255, 255, 0.95)',
            colorTextDescription: 'rgba(255, 255, 255, 0.6)',
            colorTextDisabled: 'rgba(255, 255, 255, 0.25)',
            colorIcon: 'rgba(255, 255, 255, 0.95)',
            colorIconHover: '#ffffff',
          },
          components: {
            Menu: {
              itemBg: 'transparent',
              subMenuItemBg: 'transparent',
              itemColor: 'rgba(255, 255, 255, 0.95)',
              itemHoverColor: '#ffffff',
              itemHoverBg: 'rgba(255, 255, 255, 0.1)',
              // Pill blanco de alto contraste para el ítem activo — mismo
              // criterio que la referencia: un azul-sobre-azul se pierde,
              // el blanco resalta de inmediato contra el degradado oscuro.
              itemSelectedColor: '#014693',
              itemSelectedBg: '#ffffff',
              itemActiveBg: 'rgba(255, 255, 255, 0.1)',
              // Debe verse claramente MÁS apagado que un ítem habilitado —
              // si quedan parecidos, "Próximamente" deja de leerse como
              // deshabilitado.
              itemDisabledColor: 'rgba(255, 255, 255, 0.25)',
              groupTitleColor: 'rgba(255, 255, 255, 0.4)',
              groupTitleFontSize: 11,
              itemBorderRadius: 10,
              itemMarginBlock: 3,
            },
          },
        }}
      >
        <Menu
          mode="inline"
          selectedKeys={[selectedKey]}
          items={menuItems}
          onClick={({ key }) => onSelect(key)}
          style={{ borderInlineEnd: 'none', paddingTop: 8, background: 'transparent' }}
          className="agento-sidebar-menu px-2"
        />
      </ConfigProvider>

      {/* Red de seguridad: si algún elemento interno de AntD no toma los
          tokens de arriba (ej. el título de un submenú de primer nivel
          todavía cerrado), esto fuerza el mismo blanco sin depender de eso. */}
      <style>{`
        .agento-sidebar-menu .ant-menu-submenu-title,
        .agento-sidebar-menu .ant-menu-title-content,
        .agento-sidebar-menu .ant-menu-item-icon {
          color: rgba(255, 255, 255, 0.95) !important;
        }
        .agento-sidebar-menu .ant-menu-submenu-disabled > .ant-menu-submenu-title,
        .agento-sidebar-menu .ant-menu-item-disabled .ant-menu-title-content,
        .agento-sidebar-menu .ant-menu-item-disabled .ant-menu-item-icon {
          color: rgba(255, 255, 255, 0.25) !important;
        }
        .agento-sidebar-menu .ant-menu-item-selected .ant-menu-title-content,
        .agento-sidebar-menu .ant-menu-item-selected .ant-menu-item-icon {
          color: #014693 !important;
        }
      `}</style>
    </Sider>
  );
}
