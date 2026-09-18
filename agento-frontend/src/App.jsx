import { Spin } from 'antd';
import LoginForm from './components/LoginForm';
import { useAuth } from './hooks/useAuth';
import { useCurrentUser } from './hooks/useCurrentUser';
import AppLayout from './layouts/AppLayout';
import ControlAcceso from './modules/asistencia/pages/ControlAcceso';

function App() {
  const { logout } = useAuth();
  const { user, initializing, fetchCurrentUser, clearCurrentUser, setUser } =
    useCurrentUser();

  const handleLogout = async () => {
    await logout();
    clearCurrentUser();
  };

  if (initializing) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-gradient-to-br from-agento-blue-bright via-agento-blue to-agento-blue-dark">
        <Spin size="large" />
      </div>
    );
  }

  if (!user) {
    return <LoginForm onSuccess={fetchCurrentUser} />;
  }

  // Kiosco de Control de Acceso: pantalla completa, sin el sidebar/layout
  // administrativo — se intercepta acá, antes de AppLayout, en vez de
  // agregar un router nuevo (el resto de la app no usa react-router, es un
  // árbol de condicionales dentro de AppLayout; esta ruta es la única que
  // necesita saltarse ese layout por completo).
  if (window.location.pathname === '/control-acceso') {
    return <ControlAcceso onLogout={handleLogout} />;
  }

  return (
    <AppLayout
      user={user}
      onLogout={handleLogout}
      onProfileUpdated={setUser}
      onUserRefresh={fetchCurrentUser}
    />
  );
}

export default App;
