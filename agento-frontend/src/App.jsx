import { Spin } from 'antd';
import LoginForm from './components/LoginForm';
import { useAuth } from './hooks/useAuth';
import { useCurrentUser } from './hooks/useCurrentUser';
import AppLayout from './layouts/AppLayout';
import PortalClienteApp from './modules/portal-cliente/PortalClienteApp';
import PortalNoHabilitadoScreen from './modules/portal-cliente/PortalNoHabilitadoScreen';

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

  // cliente_empresa solo debe ver el Portal Cliente, y solo si el feature
  // flag está encendido. portal_cliente_habilitado viene resuelto por el
  // backend (config('portal_cliente.enabled'), ver UserResource) — nunca se
  // decide solo con una variable del frontend. Cualquier otro rol (personal
  // interno de Agento) sigue con el sistema administrativo de siempre, sin
  // cambios. La autorización real vive en el backend — esto es solo
  // experiencia de usuario para no mostrarle el panel admin al cliente.
  if (user.role === 'cliente_empresa') {
    return user.portal_cliente_habilitado ? (
      <PortalClienteApp user={user} onLogout={handleLogout} />
    ) : (
      <PortalNoHabilitadoScreen onLogout={handleLogout} />
    );
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
