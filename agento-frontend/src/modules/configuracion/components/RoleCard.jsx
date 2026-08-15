import { iconForRole } from '../../../utils/roleIcons';

export default function RoleCard({ role }) {
  return (
    <div className="flex items-center gap-3 rounded-xl border border-gray-100 p-3 shadow-sm">
      <span className="text-xl text-agento-blue">{iconForRole(role.clave)}</span>
      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-gray-500">{role.nombre}</p>
        <p className="text-lg font-semibold text-gray-900">{role.usuarios_count}</p>
      </div>
    </div>
  );
}
