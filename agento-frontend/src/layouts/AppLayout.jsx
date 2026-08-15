import { Layout } from 'antd';
import { useState } from 'react';
import Header from '../components/layout/Header';
import Sidebar from '../components/layout/Sidebar';
import GestionEmpresas from '../pages/configuraciones/GestionEmpresas';
import GestionPersonas from '../pages/nominas/GestionPersonas';

const { Content } = Layout;

const SECCIONES = {
  'configuraciones-empresas': { title: 'Configuraciones', subtitle: 'Gestión de empresas' },
  'nominas-personas': { title: 'Nóminas', subtitle: 'Gestión de personas' },
};

export default function AppLayout({
  user,
  onLogout,
  onProfileUpdated,
  onUserRefresh,
}) {
  const [collapsed, setCollapsed] = useState(false);
  const [selectedKey, setSelectedKey] = useState('configuraciones-empresas');

  const seccion = SECCIONES[selectedKey] ?? SECCIONES['configuraciones-empresas'];

  return (
    <Layout className="min-h-svh">
      <Sidebar
        collapsed={collapsed}
        onCollapse={setCollapsed}
        selectedKey={selectedKey}
        onSelect={setSelectedKey}
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
          {selectedKey.startsWith('configuraciones-empresas') && (
            <GestionEmpresas user={user} onProfileUpdated={onProfileUpdated} />
          )}
          {selectedKey === 'nominas-personas' && (
            <GestionPersonas user={user} onUserRefresh={onUserRefresh} />
          )}
        </Content>
      </Layout>
    </Layout>
  );
}
