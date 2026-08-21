import {
  BellOutlined,
  CustomerServiceOutlined,
  LogoutOutlined,
  ReloadOutlined,
  SafetyOutlined,
  SettingOutlined,
} from '@ant-design/icons';
import { Avatar, Dropdown, Input, Layout, Tag } from 'antd';
import { useState } from 'react';
import SoporteDrawer from './SoporteDrawer';

const { Header: AntHeader } = Layout;

export default function Header({ title, subtitle, user, onLogout, onVerTickets }) {
  const initial = user?.name?.charAt(0).toUpperCase();
  const isAdmin = user?.role === 'administrador';
  const [soporteAbierto, setSoporteAbierto] = useState(false);

  const userMenuItems = [
    {
      key: 'logout',
      icon: <LogoutOutlined />,
      label: 'Cerrar sesión',
      onClick: onLogout,
    },
  ];

  return (
    <AntHeader
      className="flex items-center justify-between gap-4 border-b border-gray-100 px-6"
      style={{ background: '#fff', height: 'auto', lineHeight: 'normal', paddingBlock: 14 }}
    >
      <div className="min-w-0">
        <h1 className="truncate text-base font-semibold text-gray-900">{title}</h1>
        {subtitle && <p className="truncate text-xs text-gray-500">{subtitle}</p>}
      </div>

      <div className="flex items-center gap-2.5">
        <Input.Search
          placeholder="Buscar..."
          disabled
          className="hidden w-56 lg:inline-flex"
        />

        <div className="hidden items-center gap-0.5 rounded-lg bg-gray-50 p-1 sm:flex">
          <button
            type="button"
            onClick={() => setSoporteAbierto(true)}
            title="Soporte"
            aria-label="Soporte"
            className="flex h-7 w-7 items-center justify-center rounded-md text-agento-blue transition-colors hover:bg-white hover:text-agento-blue-dark"
          >
            <CustomerServiceOutlined />
          </button>
          <button
            type="button"
            disabled
            title="Próximamente"
            className="flex h-7 w-7 cursor-not-allowed items-center justify-center rounded-md text-gray-400"
          >
            <SettingOutlined />
          </button>
          <button
            type="button"
            disabled
            title="Próximamente"
            className="flex h-7 w-7 cursor-not-allowed items-center justify-center rounded-md text-gray-400"
          >
            <SafetyOutlined />
          </button>
          <button
            type="button"
            disabled
            title="Próximamente"
            className="flex h-7 w-7 cursor-not-allowed items-center justify-center rounded-md text-gray-400"
          >
            <ReloadOutlined />
          </button>
        </div>

        <button
          type="button"
          disabled
          className="flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg text-gray-400"
        >
          <BellOutlined />
        </button>

        <span className="hidden h-6 w-px bg-gray-100 sm:block" />

        <Dropdown menu={{ items: userMenuItems }} trigger={['click']}>
          <div className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-gray-50">
            <Avatar style={{ backgroundColor: '#014693' }}>{initial}</Avatar>
            <div className="hidden text-left sm:block">
              <p className="text-sm leading-tight font-medium whitespace-nowrap text-gray-900">
                {user?.name}
              </p>
              <Tag
                color={isAdmin ? 'blue' : 'default'}
                className="mt-0.5 leading-tight"
              >
                {isAdmin ? 'Admin' : 'Colaborador'}
              </Tag>
            </div>
          </div>
        </Dropdown>
      </div>

      <SoporteDrawer
        open={soporteAbierto}
        onClose={() => setSoporteAbierto(false)}
        onVerTickets={() => {
          setSoporteAbierto(false);
          onVerTickets?.();
        }}
      />
    </AntHeader>
  );
}
