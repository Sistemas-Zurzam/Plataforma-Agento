import { useEffect } from 'react';
import { detenerAuditoriaTracker, iniciarAuditoriaTracker } from '../services/auditoriaTracker';

/**
 * Activa el rastreo de clicks y navegación mientras haya un usuario con sesión.
 */
export function useAuditoriaTracker(user) {
  const hayUsuario = Boolean(user);

  useEffect(() => {
    if (!hayUsuario) return undefined;

    iniciarAuditoriaTracker();
    return detenerAuditoriaTracker;
  }, [hayUsuario]);
}
