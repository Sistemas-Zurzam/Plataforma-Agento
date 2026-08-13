import { EditOutlined } from '@ant-design/icons';
import { Avatar, Button, Tag } from 'antd';
import { colorForName, initialsForName } from '../utils/avatarColor';

export default function EmpresaCard({ empresa, onEdit }) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-2xl border border-gray-100 p-5 shadow-sm">
      <div className="flex items-start gap-3">
        <Avatar
          size={48}
          style={{ backgroundColor: empresa.color ?? colorForName(empresa.nombre) }}
        >
          {initialsForName(empresa.nombre)}
        </Avatar>
        <div>
          <p className="font-semibold text-gray-900">{empresa.nombre}</p>
          {empresa.grupo && (
            <p className="text-sm text-gray-500">{empresa.grupo}</p>
          )}
          {empresa.ruc && (
            <p className="text-sm text-gray-500">RUC: {empresa.ruc}</p>
          )}
          {empresa.direccion && (
            <p className="text-sm text-gray-500">{empresa.direccion}</p>
          )}
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Tag color={empresa.activa ? 'green' : 'default'}>
              {empresa.activa ? 'Activa' : 'Inactiva'}
            </Tag>
            {empresa.es_activa && <Tag color="blue">Empresa activa</Tag>}
          </div>
        </div>
      </div>

      <Button
        type="text"
        icon={<EditOutlined />}
        onClick={() => onEdit(empresa)}
        aria-label="Editar empresa"
      />
    </div>
  );
}
