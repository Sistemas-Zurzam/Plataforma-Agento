import {
  BankOutlined,
  BarChartOutlined,
  ClockCircleOutlined,
  SafetyOutlined,
  SettingOutlined,
  TeamOutlined,
  UserSwitchOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Layout, Menu } from 'antd';
import { useMemo } from 'react';
import agentoLogo from '../../assets/agento-logo.png';
import FileInvoiceDollarIcon from '../icons/FileInvoiceDollarIcon';

const { Sider } = Layout;

/**
 * "Gestión de remuneraciones" y "Gestión de asistencias" todavía no tienen
 * funcionalidad real detrás, así que quedan como vista previa de
 * "próximamente" para quien administra el sistema. "Gestión de personas"
 * ya tiene una funcionalidad real parcial (el botón "Gestión de horarios"
 * dentro de ella, ver GestionPersonas.jsx) — por eso se habilita para
 * cualquiera con el permiso horarios.ver, no solo para isAdmin. Gestión de
 * horarios NO tiene su propia entrada de sidebar a propósito: en Agento
 * (como en la referencia) es un botón dentro de Gestión de personas, no un
 * módulo aparte.
 */
function buildMenuItems(isAdmin, puedeVerHorarios) {
  const items = [];

  if (isAdmin || puedeVerHorarios) {
    items.push({
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
          disabled: true,
        },
        {
          key: 'nominas-asistencias',
          icon: <ClockCircleOutlined />,
          label: 'Gestión de asistencias',
          disabled: true,
        },
      ],
    });
  }

  if (isAdmin) {
    items.push(
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

  items.push({
    key: 'configuraciones',
    icon: <SettingOutlined />,
    label: 'Configuraciones',
    children: [
      {
        key: 'configuraciones-empresas',
        icon: <BankOutlined />,
        label: 'Gestión de empresas',
      },
      {
        key: 'configuraciones-usuarios',
        icon: <SafetyOutlined />,
        label: 'Gestión de usuarios',
        disabled: true,
      },
    ],
  });

  return items;
}

export default function Sidebar({ collapsed, onCollapse, selectedKey, onSelect, user }) {
  const isAdmin = user?.role === 'administrador';
  const puedeVerHorarios = user?.permisos?.includes('horarios.ver');
  const menuItems = useMemo(
    () => buildMenuItems(isAdmin, puedeVerHorarios),
    [isAdmin, puedeVerHorarios],
  );

  return (
    <Sider
      theme="light"
      collapsible
      collapsed={collapsed}
      onCollapse={onCollapse}
      width={264}
      className="border-r border-gray-100"
    >
      <div className="flex items-center gap-2 overflow-hidden px-4 py-5">
        <div className="h-9 w-9 shrink-0 overflow-hidden rounded-full">
          <img
            src={agentoLogo}
            alt="Agento"
            className="h-full w-full scale-125 object-cover"
          />
        </div>
        {!collapsed && (
          <span className="truncate text-lg font-semibold text-agento-blue-dark">
            Agento
          </span>
        )}
      </div>

      <Menu
        theme="light"
        mode="inline"
        selectedKeys={[selectedKey]}
        defaultOpenKeys={['configuraciones']}
        items={menuItems}
        onClick={({ key }) => onSelect(key)}
        style={{ borderInlineEnd: 'none' }}
      />
    </Sider>
  );
}
