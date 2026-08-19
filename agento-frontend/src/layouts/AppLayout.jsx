import { Layout } from 'antd';
import { useEffect, useState } from 'react';
import Header from '../components/layout/Header';
import Sidebar from '../components/layout/Sidebar';
import GestionEmpresas from '../pages/configuraciones/GestionEmpresas';
import GestionPersonas from '../pages/nominas/GestionPersonas';
import GestionRemuneraciones from '../pages/nominas/GestionRemuneraciones';
import GestionAsistencias from '../modules/asistencia/pages/GestionAsistencias';

const { Content } = Layout;

const SECCIONES = {
  'configuraciones-empresas': { title: 'Configuraciones', subtitle: 'Gestión de empresas' },
  'nominas-personas': { title: 'Nóminas', subtitle: 'Gestión de personas' },
  'nominas-remuneraciones': { title: 'Nóminas', subtitle: 'Gestión de remuneraciones' },
  'nominas-asistencias': { title: 'Nóminas', subtitle: 'Gestión de asistencias' },
};

const CONFIG_PATHS = {
  'mi-perfil': '/mi-perfil',
  empresas: '/Empresas',
  'usuarios-roles': '/usuarios-roles',
  permisos: '/permisos',
  'parametros-laborales': '/parametros-laborales',
  'comisiones-afp': '/comisiones-afp',
};

function routeFromPath(pathname) {
  const normalizedPath = pathname.toLowerCase().replace(/\/$/, '') || '/';

  if (normalizedPath === '/nominas/personas') {
    return { section: 'nominas-personas', tab: null, colaboradorId: null };
  }

  if (normalizedPath === '/nominas/remuneraciones') {
    return { section: 'nominas-remuneraciones', tab: null, colaboradorId: null };
  }

  if (normalizedPath === '/nominas/asistencias') {
    return { section: 'nominas-asistencias', tab: null, colaboradorId: null, asistenciaColaboradorId: null };
  }

  const detalleAsistencia = normalizedPath.match(/^\/nominas\/asistencias\/colaboradores\/(\d+)$/);
  if (detalleAsistencia) {
    return { section: 'nominas-asistencias', tab: null, colaboradorId: null, asistenciaColaboradorId: Number(detalleAsistencia[1]) };
  }

  const detalleColaborador = normalizedPath.match(/^\/nominas\/personas\/(\d+)$/);
  if (detalleColaborador) {
    return {
      section: 'nominas-personas',
      tab: null,
      colaboradorId: Number(detalleColaborador[1]),
    };
  }

  const tab = Object.entries(CONFIG_PATHS).find(
    ([, path]) => path.toLowerCase() === normalizedPath,
  )?.[0];

  return { section: 'configuraciones-empresas', tab: tab ?? 'mi-perfil' };
}

export default function AppLayout({
  user,
  onLogout,
  onProfileUpdated,
  onUserRefresh,
}) {
  const [collapsed, setCollapsed] = useState(false);
  const [route, setRoute] = useState(() => routeFromPath(window.location.pathname));

  useEffect(() => {
    const handlePopState = () => setRoute(routeFromPath(window.location.pathname));
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const navigate = (path) => {
    if (`${window.location.pathname}${window.location.search}` !== path) {
      window.history.pushState({}, '', path);
    }
    setRoute(routeFromPath(path.split('?')[0]));
  };

  const handleSectionSelect = (key) => {
    const paths = {
      'nominas-personas': '/nominas/personas',
      'nominas-remuneraciones': '/nominas/remuneraciones',
      'nominas-asistencias': '/nominas/asistencias',
    };
    navigate(paths[key] ?? '/mi-perfil');
  };

  const handleTabSelect = (tab) => navigate(CONFIG_PATHS[tab] ?? '/mi-perfil');

  const seccion = SECCIONES[route.section] ?? SECCIONES['configuraciones-empresas'];

  return (
    <Layout className="min-h-svh!">
      <Sidebar
        collapsed={collapsed}
        onCollapse={setCollapsed}
        selectedKey={route.section}
        onSelect={handleSectionSelect}
        user={user}
      />
      <Layout>
        <Header
          title={seccion.title}
          subtitle={seccion.subtitle}
          user={user}
          onLogout={onLogout}
          onUserRefresh={onUserRefresh}
        />
        <Content className="bg-gray-50 p-6">
          {route.section === 'configuraciones-empresas' && (
            <GestionEmpresas
              user={user}
              onProfileUpdated={onProfileUpdated}
              activeTab={route.tab}
              onTabSelect={handleTabSelect}
            />
          )}
          {route.section === 'nominas-personas' && (
            <GestionPersonas
              user={user}
              onUserRefresh={onUserRefresh}
              colaboradorId={route.colaboradorId}
              onAbrirColaborador={(id) => navigate(`/nominas/personas/${id}`)}
              onVolverColaboradores={() => navigate('/nominas/personas')}
            />
          )}
          {route.section === 'nominas-remuneraciones' && (
            <GestionRemuneraciones user={user} onUserRefresh={onUserRefresh} />
          )}
          {route.section === 'nominas-asistencias' && (
            <GestionAsistencias
              user={user}
              colaboradorId={route.asistenciaColaboradorId}
              onVerColaborador={(id) => navigate(`/nominas/asistencias/colaboradores/${id}`)}
              onVolver={() => navigate('/nominas/asistencias')}
            />
          )}
        </Content>
      </Layout>
    </Layout>
  );
}
