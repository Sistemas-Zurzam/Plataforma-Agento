import { Result } from 'antd';

/**
 * Se muestra cuando el rol de la sesión es cliente_empresa pero el feature
 * flag del Portal Cliente todavía está apagado (portal_cliente_habilitado
 * en /api/me, resuelto en el backend desde config('portal_cliente.enabled')
 * — nunca decidido solo por el frontend). No debe dar acceso al panel
 * administrativo: la única salida es cerrar sesión.
 */
export default function PortalNoHabilitadoScreen({ onLogout }) {
  return (
    <div className="flex min-h-svh items-center justify-center bg-gray-50 px-4">
      <Result
        status="info"
        title="Portal Cliente no habilitado"
        subTitle="Esta cuenta todavía no tiene el Portal Cliente disponible. Contacta a tu administrador."
        extra={
          <button
            type="button"
            onClick={onLogout}
            className="rounded-lg bg-agento-blue px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-agento-blue-dark"
          >
            Cerrar sesión
          </button>
        }
      />
    </div>
  );
}
