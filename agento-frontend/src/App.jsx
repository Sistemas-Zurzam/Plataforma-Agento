import { Spin } from 'antd';
import LoginForm from './components/LoginForm';
import { useAuth } from './hooks/useAuth';
import { useCurrentUser } from './hooks/useCurrentUser';
import AppLayout from './layouts/AppLayout';

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
