import {
  BankOutlined,
  BellOutlined,
  FileTextOutlined,
  LockOutlined,
  SafetyOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useMemo, useState } from 'react';
import ComisionesAfp from '../../modules/configuracion/pages/ComisionesAfp';
import Empresas from '../../modules/configuracion/pages/Empresas';
import ParametrosLaborales from '../../modules/configuracion/pages/ParametrosLaborales';
import Permisos from '../../modules/configuracion/pages/Permisos';
import UsuariosRoles from '../../modules/configuracion/pages/UsuariosRoles';
import MiPerfil from './MiPerfil';

/**
 * Notificaciones y Seguridad todavía no tienen funcionalidad real (ni
 * permisos propios), así que se muestran únicamente como vista previa de
 * "próximamente" para el admin — mismo criterio que ya aplica el Sidebar
 * para Nóminas/Selección/Reportes. Para cualquier otro rol quedan
 * completamente ocultas, no solo deshabilitadas. Parámetros Laborales y
 * Comisiones AFP ya son funcionalidad real: se gatean por permiso, no por
 * isAdmin.
 */
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
  const isAdmin = user?.role === 'administrador';
  const puedeVerUsuarios = user?.permisos?.includes('usuarios.ver');
  const puedeVerParametros = user?.permisos?.includes('parametros_laborales.ver');
  const puedeVerComisiones = user?.permisos?.includes('comisiones_afp.ver');

  const nominaTabs = useMemo(() => {
    const tabs = [];

    if (puedeVerParametros) {
      tabs.push({
        key: 'parametros-laborales',
        label: 'Parámetros Laborales',
        icon: <FileTextOutlined />,
      });
    }

    if (puedeVerComisiones) {
      tabs.push({
        key: 'comisiones-afp',
        label: 'Comisiones AFP',
        icon: <SafetyOutlined />,
      });
    }

    return tabs;
  }, [puedeVerParametros, puedeVerComisiones]);

  const generalTabs = useMemo(() => {
    const tabs = [
      { key: 'mi-perfil', label: 'Mi Perfil', icon: <UserOutlined /> },
      { key: 'empresas', label: 'Empresas', icon: <BankOutlined /> },
    ];

    if (puedeVerUsuarios) {
      tabs.push({
        key: 'usuarios-roles',
        label: 'Usuarios y Roles',
        icon: <TeamOutlined />,
      });
    }

    if (isAdmin) {
      tabs.push(
        { key: 'permisos', label: 'Permisos', icon: <SafetyOutlined /> },
        {
          key: 'notificaciones',
          label: 'Notificaciones',
          icon: <BellOutlined />,
          disabled: true,
        },
        { key: 'seguridad', label: 'Seguridad', icon: <LockOutlined />, disabled: true },
      );
    }

    return tabs;
  }, [isAdmin, puedeVerUsuarios]);

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

        {nominaTabs.length > 0 && (
          <>
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
          </>
        )}
      </aside>

      <section className="flex-1 rounded-2xl bg-white p-6 shadow-sm">
        {activeTab === 'mi-perfil' && (
          <MiPerfil user={user} onProfileUpdated={onProfileUpdated} />
        )}
        {activeTab === 'empresas' && <Empresas user={user} />}
        {activeTab === 'usuarios-roles' && puedeVerUsuarios && <UsuariosRoles user={user} />}
        {activeTab === 'permisos' && isAdmin && <Permisos />}
        {activeTab === 'parametros-laborales' && puedeVerParametros && (
          <ParametrosLaborales user={user} />
        )}
        {activeTab === 'comisiones-afp' && puedeVerComisiones && <ComisionesAfp user={user} />}
      </section>
    </div>
  );
}
