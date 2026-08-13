import { EditOutlined, PlusOutlined, PoweroffOutlined } from '@ant-design/icons';
import { App, Button, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { useSedes } from '../hooks/useSedes';
import SedeFormModal from './SedeFormModal';

export default function EmpresaSedes({ empresaId }) {
  const { sedes, fetchSedes, createSede, updateSede } = useSedes(empresaId);
  const { message } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [editingSede, setEditingSede] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [togglingId, setTogglingId] = useState(null);

  useEffect(() => {
    fetchSedes();
  }, [fetchSedes]);

  const openCreateModal = () => {
    setEditingSede(null);
    setModalOpen(true);
  };

  const openEditModal = (sede) => {
    setEditingSede(sede);
    setModalOpen(true);
  };

  const handleSubmit = async (values, form) => {
    setSubmitting(true);
    try {
      if (editingSede) {
        await updateSede(editingSede.id, { ...values, activa: editingSede.activa });
        message.success('Sede actualizada correctamente');
      } else {
        await createSede(values);
        message.success('Sede creada correctamente');
      }
      setModalOpen(false);
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      if (fieldErrors) {
        form.setFields(
          Object.entries(fieldErrors).map(([field, errors]) => ({
            name: field,
            errors,
          })),
        );
      } else {
        message.error('No se pudo guardar la sede');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleToggleActiva = async (sede) => {
    setTogglingId(sede.id);
    try {
      await updateSede(sede.id, {
        nombre: sede.nombre,
        direccion: sede.direccion,
        activa: !sede.activa,
      });
    } catch {
      message.error('No se pudo actualizar el estado de la sede');
    } finally {
      setTogglingId(null);
    }
  };

  return (
    <div className="rounded-xl border border-gray-100 p-4">
      <div className="mb-3 flex items-center justify-between">
        <p className="font-medium text-gray-900">Sedes</p>
        <Button size="small" icon={<PlusOutlined />} onClick={openCreateModal}>
          Nueva sede
        </Button>
      </div>

      <div className="flex flex-col gap-2">
        {sedes.map((sede) => (
          <div
            key={sede.id}
            className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2"
          >
            <div>
              <p className="text-sm font-medium text-gray-900">
                <span className="text-gray-400">{sede.codigo}</span> {sede.nombre}
              </p>
              {sede.direccion && (
                <p className="text-xs text-gray-500">{sede.direccion}</p>
              )}
            </div>
            <div className="flex items-center gap-2">
              <Tag color={sede.activa ? 'green' : 'default'}>
                {sede.activa ? 'Activa' : 'Inactiva'}
              </Tag>
              <Button
                type="text"
                size="small"
                icon={<EditOutlined />}
                onClick={() => openEditModal(sede)}
                aria-label="Editar sede"
              />
              <Button
                type="text"
                size="small"
                loading={togglingId === sede.id}
                icon={<PoweroffOutlined />}
                style={{ color: sede.activa ? '#c2410c' : '#9ca3af' }}
                onClick={() => handleToggleActiva(sede)}
                aria-label={sede.activa ? 'Desactivar sede' : 'Activar sede'}
              />
            </div>
          </div>
        ))}
        {sedes.length === 0 && (
          <p className="text-sm text-gray-400">Esta empresa todavía no tiene sedes.</p>
        )}
      </div>

      <SedeFormModal
        open={modalOpen}
        title={editingSede ? 'Editar sede' : 'Nueva sede'}
        initialValues={editingSede}
        onSubmit={handleSubmit}
        onCancel={() => setModalOpen(false)}
        submitting={submitting}
      />
    </div>
  );
}
