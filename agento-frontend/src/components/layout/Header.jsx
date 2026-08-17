import {
  BankOutlined,
  BellOutlined,
  CheckOutlined,
  DownOutlined,
  LogoutOutlined,
  ReloadOutlined,
  SafetyOutlined,
  SettingOutlined,
} from '@ant-design/icons';
import { App, Avatar, Dropdown, Input, Layout, Tag } from 'antd';
import { useEffect } from 'react';
import { useEmpresas } from '../../modules/configuracion/hooks/useEmpresas';

const { Header: AntHeader } = Layout;

export default function Header({ title, subtitle, user, onLogout, onUserRefresh }) {
  const initial = user?.name?.charAt(0).toUpperCase();
  const isAdmin = user?.role === 'administrador';
  const { message } = App.useApp();
  const { empresas, fetchEmpresas, activarEmpresa } = useEmpresas();

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const handleSwitchEmpresa = async (empresaId) => {
    if (empresaId === user?.empresa?.id) return;

    try {
      await activarEmpresa(empresaId);
      await Promise.all([fetchEmpresas(), onUserRefresh?.()]);
      message.success('Empresa activa actualizada');
    } catch {
      message.error('No se pudo cambiar de empresa');
    }
  };

  const empresaMenuItems = empresas.filter((empresa) => empresa.activa).map((empresa) => ({
    key: String(empresa.id),
    label: empresa.nombre,
    icon: empresa.es_activa ? <CheckOutlined /> : <BankOutlined />,
    onClick: () => handleSwitchEmpresa(empresa.id),
  }));

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
      style={{ background: '#fff', height: 'auto', lineHeight: 'normal' }}
    >
      <div>
        <h1 className="text-base font-semibold text-gray-900">{title}</h1>
        {subtitle && <p className="text-xs text-gray-500">{subtitle}</p>}
      </div>

      <div className="flex items-center gap-3">
        {user?.empresa && (
          <Dropdown
            menu={{ items: empresaMenuItems }}
            trigger={['click']}
            disabled={empresas.length === 0}
          >
            <button
              type="button"
              className="hidden items-center gap-1.5 rounded-lg bg-agento-blue-light px-3 py-1.5 text-sm font-medium whitespace-nowrap text-agento-blue-dark md:inline-flex"
            >
              {user.empresa.nombre}
              <DownOutlined className="text-xs" />
            </button>
          </Dropdown>
        )}

        <Input.Search
          placeholder="Buscar..."
          disabled
          className="hidden w-56 lg:inline-flex"
        />

        <button
          type="button"
          disabled
          className="hidden h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg text-gray-400 sm:flex"
        >
          <SettingOutlined />
        </button>
        <button
          type="button"
          disabled
          className="hidden h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg text-gray-400 sm:flex"
        >
          <SafetyOutlined />
        </button>
        <button
          type="button"
          disabled
          className="hidden h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg text-gray-400 sm:flex"
        >
          <ReloadOutlined />
        </button>
        <button
          type="button"
          disabled
          className="flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg text-gray-400"
        >
          <BellOutlined />
        </button>

        <Dropdown menu={{ items: userMenuItems }} trigger={['click']}>
          <div className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 hover:bg-gray-50">
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
    </AntHeader>
  );
}
