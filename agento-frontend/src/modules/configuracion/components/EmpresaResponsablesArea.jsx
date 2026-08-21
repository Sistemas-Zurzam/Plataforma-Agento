import { UserOutlined } from '@ant-design/icons';
import { App, Select } from 'antd';
import { useEffect, useState } from 'react';
import { useAreas } from '../hooks/useAreas';
import { useUsuarios } from '../hooks/useUsuarios';

/**
 * Asignación PERSISTENTE de responsable por área — distinta del rastro de
 * aprobación por-solicitud que ya existe en Gestión de asistencias (esto es
 * "quién es el responsable por defecto", no "quién aprobó esta vez").
 *
 * Nota: la lista de usuarios se trae de la empresa ACTIVA de la sesión, no
 * necesariamente de `empresaId` — si en el futuro se edita una empresa
 * distinta a la activa, este picker debería traer los usuarios de esa
 * empresa específica (hoy no existe un endpoint para eso).
 */
export default function EmpresaResponsablesArea({ empresaId, empresaNombre, user }) {
  const { areas, fetchAreas, asignarResponsable } = useAreas(empresaId);
  const { usuarios, fetchUsuarios } = useUsuarios();
  const { message } = App.useApp();
  const [savingAreaId, setSavingAreaId] = useState(null);
  const puedeEditar = user?.permisos?.includes('areas.crear');

  useEffect(() => {
    fetchAreas();
    fetchUsuarios(1, 100);
  }, [fetchAreas, fetchUsuarios]);

  const handleChange = async (area, responsableUserId) => {
    setSavingAreaId(area.id);
    try {
      await asignarResponsable(area.id, responsableUserId ?? null);
      message.success('Responsable actualizado');
    } catch (err) {
      message.error(err.response?.data?.message || 'No se pudo asignar el responsable');
    } finally {
      setSavingAreaId(null);
    }
  };

  return (
    <div className="rounded-xl border border-gray-100 p-4">
      <div className="mb-3 flex items-center gap-2">
        <UserOutlined className="text-gray-400" />
        <p className="font-medium text-gray-900">Responsables de área</p>
      </div>

      <div className="flex flex-col gap-2">
        {areas.map((area) => (
          <div key={area.id} className="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
            <p className="text-sm font-medium text-gray-900">{area.nombre}</p>
            <Select
              size="small"
              className="w-48"
              allowClear
              placeholder="Sin asignar"
              value={area.responsable_user_id ?? undefined}
              loading={savingAreaId === area.id}
              disabled={!puedeEditar}
              options={usuarios.map((u) => ({ value: u.id, label: u.name }))}
              onChange={(value) => handleChange(area, value)}
            />
          </div>
        ))}
        {areas.length === 0 && (
          <p className="text-sm text-gray-400 italic">{empresaNombre} todavía no tiene áreas registradas.</p>
        )}
      </div>
    </div>
  );
}
