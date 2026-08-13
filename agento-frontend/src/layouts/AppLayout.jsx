import { Layout } from 'antd';
import { useState } from 'react';
import Header from '../components/layout/Header';
import Sidebar from '../components/layout/Sidebar';
import GestionEmpresas from '../pages/configuraciones/GestionEmpresas';

const { Content } = Layout;

export default function AppLayout({
  user,
  onLogout,
  onProfileUpdated,
  onUserRefresh,
}) {
  const [collapsed, setCollapsed] = useState(false);
  const [selectedKey, setSelectedKey] = useState('configuraciones-empresas');

  return (
    <Layout className="min-h-svh">
      <Sidebar
        collapsed={collapsed}
        onCollapse={setCollapsed}
        selectedKey={selectedKey}
        onSelect={setSelectedKey}
      />
      <Layout>
        <Header
          title="Configuraciones"
          subtitle="Gestión de empresas"
          user={user}
          onLogout={onLogout}
          onUserRefresh={onUserRefresh}
        />
        <Content className="bg-gray-50 p-6">
          {selectedKey.startsWith('configuraciones-empresas') && (
            <GestionEmpresas user={user} onProfileUpdated={onProfileUpdated} />
          )}
        </Content>
      </Layout>
    </Layout>
  );
}
