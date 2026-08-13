import { useCallback, useEffect, useState } from 'react';
import api from '../services/api';

export function useCurrentUser() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(false);
  const [initializing, setInitializing] = useState(true);

  const fetchCurrentUser = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/me');
      setUser(data.data);
      return data.data;
    } finally {
      setLoading(false);
    }
  }, []);

  const clearCurrentUser = useCallback(() => {
    setUser(null);
  }, []);

  const restoreSession = useCallback(() => {
    if (!localStorage.getItem('access_token')) {
      setInitializing(false);
      return;
    }

    setInitializing(true);
    fetchCurrentUser()
      .catch(() => {})
      .finally(() => setInitializing(false));
  }, [fetchCurrentUser]);

  useEffect(() => {
    restoreSession();

    // The browser can restore a frozen snapshot of this page from bfcache
    // (e.g. after pressing the back/forward button) without re-running this
    // effect. Re-check the session whenever that happens so a stale
    // logged-out (or logged-in) snapshot never sticks around.
    const handlePageShow = (event) => {
      if (event.persisted) {
        restoreSession();
      }
    };

    window.addEventListener('pageshow', handlePageShow);
    return () => window.removeEventListener('pageshow', handlePageShow);
  }, [restoreSession]);

  return {
    user,
    loading,
    initializing,
    fetchCurrentUser,
    clearCurrentUser,
    setUser,
  };
}
