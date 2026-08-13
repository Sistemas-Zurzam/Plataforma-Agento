import { iconForRole } from '../utils/roleIcons';

export default function RoleCard({ role }) {
  return (
    <div className="flex flex-col items-center gap-2 rounded-2xl border border-gray-100 p-5 text-center shadow-sm">
      <span className="text-2xl text-agento-blue">{iconForRole(role.clave)}</span>
      <p className="text-sm font-medium text-gray-700">{role.nombre}</p>
      <p className="text-2xl font-semibold text-gray-900">
        {role.usuarios_count}
      </p>
    </div>
  );
}
