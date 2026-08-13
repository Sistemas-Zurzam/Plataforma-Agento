import {
  BankOutlined,
  BellOutlined,
  FileTextOutlined,
  LockOutlined,
  SafetyOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useState } from 'react';
import Empresas from './Empresas';
import MiPerfil from './MiPerfil';
import UsuariosRoles from './UsuariosRoles';

const generalTabs = [
  { key: 'mi-perfil', label: 'Mi Perfil', icon: <UserOutlined /> },
  { key: 'empresas', label: 'Empresas', icon: <BankOutlined /> },
  {
    key: 'usuarios-roles',
    label: 'Usuarios y Roles',
    icon: <TeamOutlined />,
  },
  { key: 'permisos', label: 'Permisos', icon: <SafetyOutlined />, disabled: true },
  {
    key: 'notificaciones',
    label: 'Notificaciones',
    icon: <BellOutlined />,
    disabled: true,
  },
  { key: 'seguridad', label: 'Seguridad', icon: <LockOutlined />, disabled: true },
];

const nominaTabs = [
  {
    key: 'parametros-laborales',
    label: 'Parámetros Laborales',
    icon: <FileTextOutlined />,
    disabled: true,
  },
  {
    key: 'comisiones-afp',
    label: 'Comisiones AFP',
    icon: <SafetyOutlined />,
    disabled: true,
  },
];

function TabItem({ tab, active, onSelect }) {
  return (
    <button
      type="button"
      disabled={tab.disabled}
      onClick={() => onSelect(tab.key)}
      className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition-colors ${
        active
          ? 'bg-agento-blue-light font-medium text-agento-blue-dark'
          : tab.disabled
            ? 'cursor-not-allowed text-gray-300'
            : 'text-gray-600 hover:bg-gray-50'
      }`}
    >
      <span className="text-base">{tab.icon}</span>
      {tab.label}
    </button>
  );
}

export default function GestionEmpresas({ user, onProfileUpdated }) {
  const [activeTab, setActiveTab] = useState('mi-perfil');

  return (
    <div className="flex flex-col gap-6 lg:flex-row">
      <aside className="w-full shrink-0 rounded-2xl bg-white p-4 shadow-sm lg:w-64">
        <nav className="flex flex-col gap-1">
          {generalTabs.map((tab) => (
            <TabItem
              key={tab.key}
              tab={tab}
              active={activeTab === tab.key}
              onSelect={setActiveTab}
            />
          ))}
        </nav>

        <p className="mt-5 mb-2 px-3 text-xs font-semibold tracking-wide text-gray-400 uppercase">
          Nóminas
        </p>
        <nav className="flex flex-col gap-1">
          {nominaTabs.map((tab) => (
            <TabItem
              key={tab.key}
              tab={tab}
              active={activeTab === tab.key}
              onSelect={setActiveTab}
            />
          ))}
        </nav>
      </aside>

      <section className="flex-1 rounded-2xl bg-white p-6 shadow-sm">
        {activeTab === 'mi-perfil' && (
          <MiPerfil user={user} onProfileUpdated={onProfileUpdated} />
        )}
        {activeTab === 'empresas' && <Empresas />}
        {activeTab === 'usuarios-roles' && <UsuariosRoles />}
      </section>
    </div>
  );
}
