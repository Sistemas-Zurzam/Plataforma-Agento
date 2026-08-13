import {
  BankOutlined,
  BarChartOutlined,
  ClockCircleOutlined,
  DollarOutlined,
  SafetyOutlined,
  SettingOutlined,
  TeamOutlined,
  UserSwitchOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Layout, Menu } from 'antd';
import agentoLogo from '../../assets/agento-logo.png';

const { Sider } = Layout;

const menuItems = [
  {
    key: 'nominas',
    icon: <DollarOutlined />,
    label: 'Nóminas',
    children: [
      {
        key: 'nominas-personas',
        icon: <TeamOutlined />,
        label: 'Gestión de personas',
        disabled: true,
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
  },
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
  {
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
  },
];

export default function Sidebar({ collapsed, onCollapse, selectedKey, onSelect }) {
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
