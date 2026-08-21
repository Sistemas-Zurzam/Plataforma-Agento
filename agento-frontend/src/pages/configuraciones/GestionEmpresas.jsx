import {
  BankOutlined,
  BellOutlined,
  FileTextOutlined,
  LockOutlined,
  SafetyOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useEffect, useMemo } from 'react';
import Empresas from '../../modules/configuracion/pages/Empresas';
import ParametrosRemunerativos from '../../modules/configuracion/pages/ParametrosRemunerativos';
import Permisos from '../../modules/configuracion/pages/Permisos';
import UsuariosRoles from '../../modules/configuracion/pages/UsuariosRoles';
import MiPerfil from './MiPerfil';

/**
 * Notificaciones y Seguridad todavía no tienen funcionalidad real (ni
 * permisos propios), así que se muestran únicamente como vista previa de
 * "próximamente" para el admin — mismo criterio que ya aplica el Sidebar
 * para Nóminas/Selección/Reportes. Para cualquier otro rol quedan
 * completamente ocultas, no solo deshabilitadas. Parámetros Remunerativos
 * ya es funcionalidad real (cada una de sus pestañas se gatea por su propio
 * permiso dentro de ParametrosRemunerativos), no por isAdmin.
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

export default function GestionEmpresas({
  user,
  onProfileUpdated,
  activeTab = 'mi-perfil',
  onTabSelect,
}) {
  const isAdmin = user?.role === 'administrador';
  const puedeVerUsuarios = user?.permisos?.includes('usuarios.ver');
  const puedeVerParametrosRemunerativos =
    user?.permisos?.includes('parametros_laborales.ver') ||
    user?.permisos?.includes('comisiones_afp.ver') ||
    user?.permisos?.includes('nominas.ver') ||
    user?.permisos?.includes('empresas.editar');

  const nominaTabs = useMemo(() => {
    if (!puedeVerParametrosRemunerativos) return [];

    return [
      {
        key: 'parametros-remunerativos',
        label: 'Parámetros Remunerativos',
        icon: <FileTextOutlined />,
      },
    ];
  }, [puedeVerParametrosRemunerativos]);

  const cuentaTabs = useMemo(
    () => [{ key: 'mi-perfil', label: 'Mi Perfil', icon: <UserOutlined /> }],
    [],
  );

  const organizacionTabs = useMemo(() => {
    const tabs = [{ key: 'empresas', label: 'Empresas', icon: <BankOutlined /> }];

    if (puedeVerUsuarios) {
      tabs.push({
        key: 'usuarios-roles',
        label: 'Usuarios y Roles',
        icon: <TeamOutlined />,
      });
    }

    if (isAdmin) {
      tabs.push({ key: 'permisos', label: 'Permisos', icon: <SafetyOutlined /> });
    }

    return tabs;
  }, [isAdmin, puedeVerUsuarios]);

  const sistemaTabs = useMemo(() => {
    if (!isAdmin) return [];

    return [
      { key: 'notificaciones', label: 'Notificaciones', icon: <BellOutlined />, disabled: true },
      { key: 'seguridad', label: 'Seguridad', icon: <LockOutlined />, disabled: true },
    ];
  }, [isAdmin]);

  const availableTabs = useMemo(
    () => [...cuentaTabs, ...organizacionTabs, ...sistemaTabs, ...nominaTabs]
      .filter((tab) => !tab.disabled)
      .map((tab) => tab.key),
    [cuentaTabs, organizacionTabs, sistemaTabs, nominaTabs],
  );

  useEffect(() => {
    if (!availableTabs.includes(activeTab)) {
      onTabSelect?.('mi-perfil');
    }
  }, [activeTab, availableTabs, onTabSelect]);

  const grupos = [
    { label: null, tabs: cuentaTabs },
    { label: 'Organización', tabs: organizacionTabs },
    { label: 'Sistema', tabs: sistemaTabs },
    { label: 'Nóminas', tabs: nominaTabs },
  ].filter((grupo) => grupo.tabs.length > 0);

  return (
    <div className="flex flex-col gap-6 lg:flex-row">
      <aside className="w-full shrink-0 rounded-2xl bg-white p-4 shadow-sm lg:w-64">
        {grupos.map((grupo, index) => (
          <div key={grupo.label ?? 'cuenta'} className={index > 0 ? 'mt-5' : ''}>
            {grupo.label && (
              <p className="mb-2 px-3 text-xs font-semibold tracking-wide text-gray-400 uppercase">
                {grupo.label}
              </p>
            )}
            <nav className="flex flex-col gap-1">
              {grupo.tabs.map((tab) => (
                <TabItem
                  key={tab.key}
                  tab={tab}
                  active={activeTab === tab.key}
                  onSelect={onTabSelect}
                />
              ))}
            </nav>
          </div>
        ))}
      </aside>

      <section className="flex-1 rounded-2xl bg-white p-6 shadow-sm">
        {activeTab === 'mi-perfil' && (
          <MiPerfil user={user} onProfileUpdated={onProfileUpdated} />
        )}
        {activeTab === 'empresas' && <Empresas user={user} />}
        {activeTab === 'usuarios-roles' && puedeVerUsuarios && <UsuariosRoles user={user} />}
        {activeTab === 'permisos' && isAdmin && <Permisos />}
        {activeTab === 'parametros-remunerativos' && puedeVerParametrosRemunerativos && (
          <ParametrosRemunerativos user={user} />
        )}
      </section>
    </div>
  );
}
